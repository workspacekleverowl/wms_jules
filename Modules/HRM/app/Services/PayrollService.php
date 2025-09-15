<?php

namespace Modules\HRM\Services;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use App\Models\Employee;
use App\Models\SalarySlip;
use App\Models\Holiday;
use App\Models\AttendanceRecord;
use App\Models\Advance;

class PayrollService
{
    protected $advanceStatusService;

    public function __construct(AdvanceStatusService $advanceStatusService)
    {
        $this->advanceStatusService = $advanceStatusService;
    }

    public function generateDraftSlip(Employee $employee, int $month, int $year, int $companyId, string $tenantId): SalarySlip
    {
        // 1. Determine pay period and check for duplicates
        $start = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $exists = SalarySlip::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->where('pay_period_start', $start->toDateString())
            ->where('pay_period_end', $end->toDateString())
            ->exists();

        if ($exists) {
            throw new \Exception('A salary slip for this employee and pay period already exists.');
        }

        // --- REFACTORED LOGIC STARTS HERE ---

        // TODO: Fetch these settings from company configuration in the database
        $exclude_weekdays_from_salary = 'yes'; // Can be 'yes' or 'no'
        $weekdayToExclude = Carbon::SUNDAY;
        $daily_working_hours = 8; // Define standard daily working hours

        // 2. Calculate total working days in the month (this is the basis for the hourly rate)
        $daysInMonth = $start->daysInMonth;
        $totalWorkingDaysInMonth = $daysInMonth;

        if ($exclude_weekdays_from_salary === 'yes') {
            $sundaysInMonth = 0;
            $period = CarbonPeriod::create($start, $end);
            foreach ($period as $date) {
                if ($date->dayOfWeek === $weekdayToExclude) {
                    $sundaysInMonth++;
                }
            }
            $totalWorkingDaysInMonth = $daysInMonth - $sundaysInMonth;
        }

        if ($totalWorkingDaysInMonth <= 0 || $daily_working_hours <= 0) {
            throw new \Exception("Total working days or daily working hours must be positive. Check company settings for the period.");
        }
        
        // 3. Calculate employee's base hourly rate
        $hourlyRate = ($employee->basic_salary / $totalWorkingDaysInMonth) / $daily_working_hours;

        // 4. Calculate total actual worked hours from attendance records
        $attendanceRecords = AttendanceRecord::with('breaks')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->whereBetween('attendance_date', [$start, $end])
            ->where('status', 'Present')
            ->get();

        $totalWorkedMinutes = 0;
        foreach ($attendanceRecords as $record) {
            if ($record->check_in_time && $record->check_out_time) {
                $checkIn = Carbon::parse($record->check_in_time);
                $checkOut = Carbon::parse($record->check_out_time);
                
                $dailyTotalMinutes = $checkIn->diffInMinutes($checkOut);

                $totalBreakMinutes = 0;
                foreach ($record->breaks as $break) {
                    $breakStart = Carbon::parse($break->break_start_time);
                    $breakEnd = Carbon::parse($break->break_end_time);
                    $totalBreakMinutes += $breakStart->diffInMinutes($breakEnd);
                }

                

                $dailyWorkedMinutes = $dailyTotalMinutes - $totalBreakMinutes;
                // Ensure daily worked time is not negative
                $totalWorkedMinutes += max(0, $dailyWorkedMinutes);
            }
        }
        // Convert total minutes to hours for calculation
        $total_worked_hours = round(($totalWorkedMinutes / 60),2);
        $hourlyRate= round($hourlyRate,2);
        
        // 5. Calculate prorated basic salary based on actual hours worked
        $proratedBasicSalary = $total_worked_hours * $hourlyRate;

        $slipDetails = [];
        $grossSalary = 0;
        $totalDeductions = 0;

        // Add prorated basic salary to details
        $slipDetails[] = [
            'component_name' => 'Basic Salary',
            'component_type' => 'Allowance',
            'amount' => round($proratedBasicSalary, 2),
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'can_edit' => "false"
        ];
        $grossSalary += $proratedBasicSalary;

        // 6. Calculate and prorate all other salary components based on hours worked
        foreach ($employee->salaryComponents as $component) {
            // dd($component);
            $baseAmount = 0; // Monthly base amount for the component
            if ($component->calculation_type === 'Fixed') {
                $baseAmount = $component->pivot->custom_value;
                // dd($baseAmount);
            } elseif ($component->calculation_type === 'Percentage') {
                $baseAmount = ($employee->basic_salary * $component->pivot->custom_value) / 100;
            }

            // Convert monthly component amount to an hourly rate
            $componentHourlyRate = ($baseAmount / $totalWorkingDaysInMonth) / $daily_working_hours;
            
            // Calculate prorated amount for the component based on actual hours worked
            $proratedAmount = $componentHourlyRate * $total_worked_hours;

            $slipDetails[] = [
                'component_id' => $component->id,
                'component_name' => $component->name,
                'component_type' => $component->type,
                'amount' => round($proratedAmount, 2),
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'can_edit' => "true"
            ];

            if ($component->type === 'Allowance') {
                $grossSalary += $proratedAmount;
            } else {
                $totalDeductions += $proratedAmount;
            }
        }

        // 7. Handle non-prorated deductions like advances
        $activeAdvance = Advance::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->where('status', 'Active')
            ->first();

        if ($activeAdvance) {
            $deductionAmount = $activeAdvance->monthly_installment;
            $slipDetails[] = [
                'component_name' => 'Advance Repayment',
                'component_type' => 'Deduction',
                'amount' => round($deductionAmount, 2),
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'can_edit' => "true"
            ];
            $totalDeductions += $deductionAmount;
        }

        // 8. Create the SalarySlip record with new hourly data
        $netSalary = $grossSalary - $totalDeductions;
        $salarySlip = DB::transaction(function () use ($employee, $start, $end, $grossSalary, $totalDeductions, $netSalary, $slipDetails, $companyId, $tenantId, $hourlyRate, $total_worked_hours) {
            $slip = SalarySlip::create([
                'employee_id' => $employee->id,
                'pay_period_start' => $start->toDateString(),
                'pay_period_end' => $end->toDateString(),
                'gross_salary' => round($grossSalary, 2),
                'total_deductions' => round($totalDeductions, 2),
                'net_salary' => round($netSalary, 2),
                'status' => 'Generated',
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                // Add new fields
                'hourly_charges' => round($hourlyRate, 4), // Store with more precision
                'total_worked_hours' => round($total_worked_hours, 2),
            ]);
            $slip->details()->createMany($slipDetails);
            return $slip;
        });

        return $salarySlip->load('details');
    }

    public function finalizeSlip(SalarySlip $slip, array $finalDetails): SalarySlip
    {
        if ($slip->status !== 'Generated') {
            throw new \Exception('Only a generated slip can be finalized.');
        }

        $grossSalary = 0;
        $totalDeductions = 0;

        $tenantId = $slip->tenant_id;
        $companyId = $slip->company_id;

        $processedDetails = array_map(function($detail) use ($tenantId, $companyId) {
            $detail['tenant_id'] = $tenantId;
            $detail['company_id'] = $companyId;
            return $detail;
        }, $finalDetails);

        foreach ($processedDetails as $detail) {
            if ($detail['component_type'] === 'Allowance') {
                $grossSalary += $detail['amount'];
            } else {
                $totalDeductions += $detail['amount'];
            }
        }

        DB::transaction(function () use ($slip, $processedDetails, $grossSalary, $totalDeductions) {
            $slip->details()->delete();
            $slip->details()->createMany($processedDetails);
            $slip->update([
                'gross_salary' => round($grossSalary, 2),
                'total_deductions' => round($totalDeductions, 2),
                'net_salary' => round($grossSalary - $totalDeductions, 2),
            ]);
        });

        return $slip->load('details');
    }

    public function markSlipAsPaid(SalarySlip $slip): SalarySlip
    {
        if ($slip->status !== 'Generated') {
            throw new \Exception('Only a generated slip can be marked as paid.');
        }

        DB::transaction(function () use ($slip) {
            $slip->update(['status' => 'Paid']);

            $advanceDetail = $slip->details()->where('component_name', 'Advance Repayment')->first();

            if ($advanceDetail) {
                $advance = Advance::where('tenant_id', $slip->tenant_id)
                    ->where('company_id', $slip->company_id)
                    ->where('employee_id', $slip->employee_id)
                    ->where('status', 'Active')
                    ->first();
                if ($advance) {
                    $advance->repayments()->create([
                        'payment_date' => Carbon::now()->toDateString(),
                        'amount_paid' => $advanceDetail->amount,
                        'payment_type' => 'Salary Deduction',
                        'salary_slip_id' => $slip->id,
                        'tenant_id' => $slip->tenant_id,
                        'company_id' => $slip->company_id,
                    ]);

                    // Re-evaluate the advance status
                    $this->advanceStatusService->updateAdvanceStatus($advance);
                }
            }
        });

        return $slip;
    }
}


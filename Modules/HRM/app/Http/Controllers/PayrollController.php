<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\ApiController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use App\Models\Employee;
use Modules\HRM\Services\PayrollService;
use Illuminate\Support\Facades\Log;

class PayrollController extends ApiController
{
    protected $payrollService;

    public function __construct(PayrollService $payrollService)
    {
        $this->payrollService = $payrollService;
    }

    /**
     * Generate a new draft salary slip for an employee.
     *
     * @param GeneratePayrollRequest $request
     * @return JsonResponse
     */
    public function generate(GeneratePayrollRequest $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();
        $month = $request->month;
        $year = $request->year;

        // Fetch employees based on request
        if ($request->filled('employee_id')) {
            $employees = Employee::where('tenant_id', $tenantId)->where('company_id', $activeCompanyId)->where('id', $request->employee_id)->get();
            if ($employees->isEmpty()) {
                return $this->errorResponse('Employee not found.', 404);
            }
        } else {
            $employees = Employee::where('tenant_id', $tenantId)->where('company_id', $activeCompanyId)->get();
        }

        $results = [
            'successful_slips' => [],
            'skipped_employees' => [],
            'failed_employees' => [],
        ];

        foreach ($employees as $employee) {
            try {
                // The service will throw an exception if a slip already exists.
                $salarySlip = $this->payrollService->generateDraftSlip(
                    $employee,
                    $month,
                    $year,
                    $activeCompanyId,
                    $tenantId
                );

                // Manually construct the response to avoid dependency on SalarySlipResource
                $results['successful_slips'][] = [
                    'id' => $salarySlip->id,
                    'employee_id' => $salarySlip->employee_id,
                    'employee_name' => $employee->name, // Added for clarity
                    'pay_period_start' => $salarySlip->pay_period_start->format('Y-m-d'),
                    'pay_period_end' => $salarySlip->pay_period_end->format('Y-m-d'),
                    'gross_salary' => $salarySlip->gross_salary,
                    'total_deductions' => $salarySlip->total_deductions,
                    'net_salary' => $salarySlip->net_salary,
                    'status' => $salarySlip->status,
                ];
            } catch (\Exception $e) {
                // Condition to skip already generated slips
                if (str_contains($e->getMessage(), 'already exists')) {
                    $results['skipped_employees'][] = ['employee_id' => $employee->id, 'reason' => $e->getMessage()];
                } else {
                    // Log other unexpected errors
                    Log::error("Payroll generation failed for employee {$employee->id}: " . $e->getMessage());
                    $results['failed_employees'][] = ['employee_id' => $employee->id, 'reason' => 'An unexpected error occurred.'];
                }
            }
        }

        // For a single employee request, return the slip data directly or the error
        if ($request->filled('employee_id')) {
            if (!empty($results['successful_slips'])) {
                return $this->successResponse($results['successful_slips'][0], 'Draft salary slip generated successfully.', 201);
            } elseif (!empty($results['skipped_employees'])) {
                 return $this->errorResponse($results['skipped_employees'][0]['reason'], 409); // 409 Conflict
            } else {
                 return $this->errorResponse($results['failed_employees'][0]['reason'], 500);
            }
        }

        // For batch requests, return a summary
        return $this->successResponse($results, 'Payroll generation process completed.');
    
    }
}

class GeneratePayrollRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $user = $this->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        return [
            'employee_id' => [
                'nullable', // Changed from 'required'
                'integer',
                Rule::exists('employees', 'id')->where('tenant_id', $tenantId)->where('company_id', $activeCompanyId),
            ],
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'date_format:Y'],
        ];
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }
}
<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\ApiController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\Rule;
use App\Models\SalarySlip;
use Modules\HRM\Services\PayrollService;

class SalarySlipController extends ApiController
{
    protected $payrollService;

    public function __construct(PayrollService $payrollService)
    {
        $this->payrollService = $payrollService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        // Get authenticated user and their tenant/company context.
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            // Get pagination and search parameters from the request.
            $perPage = $request->input('per_page', 10);
            $search = $request->input('search');

            // Start building the Eloquent query with tenancy constraints.
            $query = SalarySlip::query()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId);

            // Apply search logic if a search term is provided.
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('status', 'like', "%{$search}%")
                        ->orWhereHas('employee', function ($relationQuery) use ($search) {
                            $relationQuery->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                        });
                });
            }

            // Apply filters
            if ($request->has('employee_id')) {
                $query->where('employee_id', $request->employee_id);
            }
            if ($request->has('pay_period_start')) {
                $query->where('pay_period_start', '>=', $request->pay_period_start);
            }
            if ($request->has('pay_period_end')) {
                $query->where('pay_period_end', '<=', $request->pay_period_end);
            }
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Eager load any necessary relationships for the resource.
            $query->with(['employee']);

            // Paginate the results.
            $results = $query->latest()->paginate($perPage);

            // Return a standardized paginated response using the ApiController trait.
            return $this->paginatedResponse($results, 'Salary slips retrieved successfully.');

        } catch (\Exception $e) {
            // Return a standardized error response in case of failure.
            return $this->errorResponse('Failed to retrieve resources: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        $salarySlip = SalarySlip::where('tenant_id', $tenantId)->where('company_id', $activeCompanyId)->find($id);
        if (!$salarySlip) {
            return $this->errorResponse('Salary slip not found.', 404);
        }
        $salarySlip->load('details');
        return $this->successResponse(new SalarySlipResource($salarySlip), 'Salary slip retrieved successfully.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSalarySlipRequest $request, $id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        $salarySlip = SalarySlip::where('tenant_id', $tenantId)->where('company_id', $activeCompanyId)->find($id);
        if (!$salarySlip) {
            return $this->errorResponse('Salary slip not found.', 404);
        }
        try {
            $updatedSlip = $this->payrollService->finalizeSlip($salarySlip, $request->details);
            return $this->successResponse(new SalarySlipResource($updatedSlip), 'Salary slip finalized successfully.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 409);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        $salarySlip = SalarySlip::where('tenant_id', $tenantId)->where('company_id', $activeCompanyId)->find($id);
        if (!$salarySlip) {
            return $this->errorResponse('Salary slip not found.', 404);
        }
        if ($salarySlip->status !== 'Generated') {
            return $this->errorResponse('Only generated salary slips can be deleted.', 409);
        }

        $salarySlip->details()->delete();
        $salarySlip->delete();

        return $this->successResponse(null, 'Salary slip deleted successfully.');
    }

    /**
     * Mark the specified salary slip as paid.
     */
    public function pay(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        $salarySlip = SalarySlip::where('tenant_id', $tenantId)->where('company_id', $activeCompanyId)->find($id);
        if (!$salarySlip) {
            return $this->errorResponse('Salary slip not found.', 404);
        }
        try {
            $paidSlip = $this->payrollService->markSlipAsPaid($salarySlip);
            return $this->successResponse(new SalarySlipResource($paidSlip), 'Salary slip marked as paid successfully.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 409);
        }
    }
}

class UpdateSalarySlipRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'details' => 'required|array',
            'details.*.component_name' => 'required|string|max:255',
            'details.*.component_type' => ['required', Rule::in(['Allowance', 'Deduction'])],
            'details.*.amount' => 'required|numeric|min:0',
            'details.*.component_id' => 'nullable|integer', // Optional, as some details like basic salary might not have a component id
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

class SalarySlipResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'pay_period_start' => $this->pay_period_start->format('Y-m-d'),
            'pay_period_end' => $this->pay_period_end->format('Y-m-d'),
            'gross_salary' => $this->gross_salary,
            'total_deductions' => $this->total_deductions,
            'net_salary' => $this->net_salary,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'details' => $this->whenLoaded('details', function () {
                return $this->details->map(function ($detail) {
                    return [
                        'id' => $detail->id,
                        'salary_slip_id' => $detail->salary_slip_id,
                        'component_id' => $detail->component_id,
                        'component_name' => $detail->component_name,
                        'component_type' => $detail->component_type,
                        'amount' => $detail->amount,
                        'can_edit' => $detail->can_edit,
                    ];
                });
            }),
        ];
    }
}

class SalarySlipDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'salary_slip_id' => $this->salary_slip_id,
            'component_id' => $this->component_id,
            'component_name' => $this->component_name,
            'component_type' => $this->component_type,
            'amount' => $this->amount,
        ];
    }
}
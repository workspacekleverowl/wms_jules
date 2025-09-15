<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\Employee;

class EmployeeController extends ApiController
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        // $permissionResponse = $this->checkPermission('HRM-Employee-Show');
        // if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $perPage = $request->input('per_page', 10);
            $search = $request->input('search');

            $query = Employee::with(['department', 'designation'])
                ->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId);

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('employee_id', 'like', "%{$search}%")
                      ->orWhereHas('department', fn($dq) => $dq->where('name', 'like', "%{$search}%"))
                      ->orWhereHas('designation', fn($dq) => $dq->where('title', 'like', "%{$search}%"));
                });
            }

            $employees = $query->latest()->paginate($perPage);

            return $this->paginatedResponse($employees, 'Employees retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve employees: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        // $permissionResponse = $this->checkPermission('HRM-Employee-Insert');
        // if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        $validator = $this->validateEmployee($request, 'store', null, $tenantId, $activeCompanyId);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }

        DB::beginTransaction();
        try {
            $validatedData = $validator->validated();
            $validatedData['tenant_id'] = $tenantId;
            $validatedData['company_id'] = $activeCompanyId;
            
            $employee = Employee::create($validatedData);
            DB::commit();

            return $this->show($request, $employee->id);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to create employee: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, $id): JsonResponse
    {
        // $permissionResponse = $this->checkPermission('HRM-Employee-Show');
        // if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $employee = Employee::with(['department', 'designation'])
                ->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($id);

            if (!$employee) {
                return $this->errorResponse('Employee not found', 404);
            }

            return $this->successResponse($employee, 'Employee retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve employee: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id): JsonResponse
    {
        // $permissionResponse = $this->checkPermission('HRM-Employee-Update');
        // if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        DB::beginTransaction();
        try {
            $employee = Employee::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($id);

            if (!$employee) {
                return $this->errorResponse('Employee not found', 404);
            }

            $validator = $this->validateEmployee($request, 'update', $id, $tenantId, $activeCompanyId);

            if ($validator->fails()) {
                return $this->errorResponse($validator->errors()->all(), 422);
            }

            $employee->update($validator->validated());
            DB::commit();

            return $this->show($request, $id);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to update employee: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        // $permissionResponse = $this->checkPermission('HRM-Employee-Delete');
        // if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $employee = Employee::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($id);

            if (!$employee) {
                return $this->errorResponse('Employee not found', 404);
            }
            
            // Add checks for related data if needed, e.g., unpaid salaries
            // if ($employee->salarySlips()->where('status', '!=', 'Paid')->exists()) {
            //     return $this->errorResponse('Cannot delete. Employee has unpaid salary slips.', 409);
            // }

            $employee->delete();

            return $this->successResponse(null, 'Employee deleted successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to delete employee: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Private validation helper method to reduce code duplication.
     */
    private function validateEmployee(Request $request, string $method, ?int $employeeId, string $tenantId, int $companyId)
    {
        $isPost = $method === 'store';
        $required = $isPost ? 'required' : 'sometimes';
        $departmentId = $request->input('department_id');

        $rules = [
            'first_name'        => [$required, 'string', 'max:100'],
            'last_name'         => [$required, 'string', 'max:100'],
            'hire_date'         => [$required, 'date'],
            'basic_salary'      => [$required, 'numeric', 'min:0'],
            'gender'            => ['nullable', Rule::in(['Male', 'Female', 'Other'])],
            'status'            => ['nullable', Rule::in(['Active', 'On Leave', 'Terminated'])],
            'date_of_birth'     => 'nullable|date|before_or_equal:today',
            'termination_date'  => 'nullable|date|after_or_equal:hire_date',
            'address'           => 'nullable|string',
            'bank_name'         => 'nullable|string|max:255',
            'bank_ifsc'         => 'nullable|string|max:20',
            'branch_location'   => 'nullable|string|max:255',

            'employee_id' => [
                $required, 'string', 'max:50',
                "unique:employees,employee_id,{$employeeId},id,tenant_id,{$tenantId},company_id,{$companyId}"
            ],
            'email' => [
                $required, 'email', 'max:255',
                "unique:employees,email,{$employeeId},id,tenant_id,{$tenantId},company_id,{$companyId}"
            ],
            'phone_number' => [
                'nullable', 'string', 'max:20',
                "unique:employees,phone_number,{$employeeId},id,tenant_id,{$tenantId},company_id,{$companyId}"
            ],
            'department_id' => [
                $required, 'integer',
                "exists:departments,id,tenant_id,{$tenantId},company_id,{$companyId}"
            ],
            'designation_id' => [
                $required, 'integer',
                "exists:designations,id,department_id,{$departmentId},tenant_id,{$tenantId},company_id,{$companyId}"
            ],
        ];

        return Validator::make($request->all(), $rules);
    }
}
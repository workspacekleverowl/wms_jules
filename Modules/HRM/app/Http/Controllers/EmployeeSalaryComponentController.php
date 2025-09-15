<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\Employee;
use App\Models\SalaryComponent;

class EmployeeSalaryComponentController extends ApiController
{
    public function index(Request $request, $employee_id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $employee = Employee::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($employee_id);

            if (!$employee) {
                return $this->errorResponse('Employee not found', 404);
            }

            $components = $employee->salaryComponents()->get();

            return $this->successResponse($components, 'Employee salary components retrieved successfully.');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve employee salary components: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request, $employee_id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        $validator = Validator::make($request->all(), [
            'component_id' => [
                'required', 'integer',
                "exists:salary_components,id,tenant_id,{$tenantId},company_id,{$activeCompanyId}"
            ],
            'custom_value' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }

        DB::beginTransaction();
        try {
            $employee = Employee::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($employee_id);

            if (!$employee) {
                return $this->errorResponse('Employee not found', 404);
            }

            $data = [
                $request->component_id => [
                    'custom_value' => $request->custom_value,
                    'tenant_id' => $tenantId,
                    'company_id' => $activeCompanyId,
                ]
            ];

            $employee->salaryComponents()->syncWithoutDetaching($data);
            DB::commit();

            return $this->successResponse(null, 'Salary component assigned successfully.', 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to assign salary component: ' . $e->getMessage(), 500);
        }
    }

    public function update(Request $request, $employee_id, $salary_component_id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        $validator = Validator::make($request->all(), [
            'custom_value' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }

        DB::beginTransaction();
        try {
            $employee = Employee::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($employee_id);

            if (!$employee) {
                return $this->errorResponse('Employee not found', 404);
            }

            $data = [
                'custom_value' => $request->custom_value,
            ];

            $employee->salaryComponents()->updateExistingPivot($salary_component_id, $data);
            DB::commit();

            return $this->successResponse(null, 'Salary component assignment updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to update salary component assignment: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(Request $request, $employee_id, $salary_component_id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        DB::beginTransaction();
        try {
            $employee = Employee::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($employee_id);

            if (!$employee) {
                return $this->errorResponse('Employee not found', 404);
            }

            $employee->salaryComponents()->detach($salary_component_id);
            DB::commit();

            return $this->successResponse(null, 'Salary component unassigned successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to unassign salary component: ' . $e->getMessage(), 500);
        }
    }
}

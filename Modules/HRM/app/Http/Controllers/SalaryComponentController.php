<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\SalaryComponent;

class SalaryComponentController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $perPage = $request->input('per_page', 10);
            $search = $request->input('search');

            $query = SalaryComponent::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId);

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('type', 'like', "%{$search}%");
                });
            }

            $components = $query->latest()->paginate($perPage);

            return $this->paginatedResponse($components, 'Salary Components retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve salary components: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        $validator = $this->validateSalaryComponent($request, 'store', null, $tenantId, $activeCompanyId);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }

        DB::beginTransaction();
        try {
            $validatedData = $validator->validated();
            $validatedData['tenant_id'] = $tenantId;
            $validatedData['company_id'] = $activeCompanyId;

            $component = SalaryComponent::create($validatedData);
            DB::commit();

            return $this->show($request, $component->id);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to create salary component: ' . $e->getMessage(), 500);
        }
    }

    public function show(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $component = SalaryComponent::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($id);

            if (!$component) {
                return $this->errorResponse('Salary Component not found', 404);
            }

            return $this->successResponse($component, 'Salary Component retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve salary component: ' . $e->getMessage(), 500);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        DB::beginTransaction();
        try {
            $component = SalaryComponent::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($id);

            if (!$component) {
                return $this->errorResponse('Salary Component not found', 404);
            }

            $validator = $this->validateSalaryComponent($request, 'update', $id, $tenantId, $activeCompanyId);

            if ($validator->fails()) {
                return $this->errorResponse($validator->errors()->all(), 422);
            }

            $component->update($validator->validated());
            DB::commit();

            return $this->show($request, $id);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to update salary component: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $component = SalaryComponent::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($id);

            if (!$component) {
                return $this->errorResponse('Salary Component not found', 404);
            }

            $component->delete();

            return $this->successResponse(null, 'Salary Component deleted successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to delete salary component: ' . $e->getMessage(), 500);
        }
    }

    private function validateSalaryComponent(Request $request, string $method, ?int $componentId, string $tenantId, int $companyId)
    {
        $isPost = $method === 'store';
        $required = $isPost ? 'required' : 'sometimes';

        $rules = [
            'name' => [
                $required, 'string', 'max:255',
                "unique:salary_components,name,{$componentId},id,tenant_id,{$tenantId},company_id,{$companyId}"
            ],
            'type' => [$required, Rule::in(['Allowance', 'Deduction'])],
            'calculation_type' => [$required, Rule::in(['Fixed', 'Percentage'])],
            'value' => [$required, 'numeric', 'min:0'],
            'is_active' => 'sometimes|boolean',
            'parent_id' => [
                'nullable',
                'integer',
                "exists:salary_components,id,tenant_id,{$tenantId},company_id,{$companyId}"
            ],
        ];

        return Validator::make($request->all(), $rules);
    }
}

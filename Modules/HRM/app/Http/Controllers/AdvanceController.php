<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\Advance;

class AdvanceController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $perPage = $request->input('per_page', 10);
            $search = $request->input('search');

            $query = Advance::with('employee')
                ->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId);

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('status', 'like', "%{$search}%")
                      ->orWhereHas('employee', function ($eq) use ($search) {
                          $eq->where('first_name', 'like', "%{$search}%")
                             ->orWhere('last_name', 'like', "%{$search}%");
                      });
                });
            }

            $advances = $query->latest()->paginate($perPage);

            return $this->paginatedResponse($advances, 'Advances retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve advances: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        $validator = $this->validateAdvance($request, 'store', null, $tenantId, $activeCompanyId);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }

        DB::beginTransaction();
        try {
            $validatedData = $validator->validated();
            $validatedData['tenant_id'] = $tenantId;
            $validatedData['company_id'] = $activeCompanyId;

            $advance = Advance::create($validatedData);
            DB::commit();

            return $this->show($request, $advance->id);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to create advance: ' . $e->getMessage(), 500);
        }
    }

    public function show(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $advance = Advance::with('employee', 'repayments')
                ->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($id);

            if (!$advance) {
                return $this->errorResponse('Advance not found', 404);
            }

            return $this->successResponse($advance, 'Advance retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve advance: ' . $e->getMessage(), 500);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        DB::beginTransaction();
        try {
            $advance = Advance::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($id);

            if (!$advance) {
                return $this->errorResponse('Advance not found', 404);
            }

            $validator = $this->validateAdvance($request, 'update', $id, $tenantId, $activeCompanyId);

            if ($validator->fails()) {
                return $this->errorResponse($validator->errors()->all(), 422);
            }

            $advance->update($validator->validated());
            DB::commit();

            return $this->show($request, $id);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to update advance: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $advance = Advance::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($id);

            if (!$advance) {
                return $this->errorResponse('Advance not found', 404);
            }

            if ($advance->repayments()->exists()) {
                return $this->errorResponse('Cannot delete advance with active repayments.', 409);
            }

            $advance->delete();

            return $this->successResponse(null, 'Advance deleted successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to delete advance: ' . $e->getMessage(), 500);
        }
    }

    private function validateAdvance(Request $request, string $method, ?int $advanceId, string $tenantId, int $companyId)
    {
        $isPost = $method === 'store';
        $required = $isPost ? 'required' : 'sometimes';

        $rules = [
            'employee_id' => [
                $required, 'integer',
                "exists:employees,id,tenant_id,{$tenantId},company_id,{$companyId}"
            ],
            'advance_date' => [$required, 'date'],
            'advance_amount' => [$required, 'numeric', 'min:0'],
            'monthly_installment' => [$required, 'numeric', 'min:0', 'lte:advance_amount'],
            'status' => ['sometimes', Rule::in(['Active', 'Paid Off'])],
            'notes' => 'nullable|string',
        ];

        return Validator::make($request->all(), $rules);
    }
}

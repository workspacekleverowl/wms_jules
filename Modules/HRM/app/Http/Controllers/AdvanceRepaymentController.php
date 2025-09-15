<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\Advance;
use App\Models\AdvanceRepayment;
use Modules\HRM\Services\AdvanceStatusService;

class AdvanceRepaymentController extends ApiController
{
    protected $advanceStatusService;

    public function __construct(AdvanceStatusService $advanceStatusService)
    {
        $this->advanceStatusService = $advanceStatusService;
    }

    public function index(Request $request, $advance_id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $advance = Advance::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($advance_id);

            if (!$advance) {
                return $this->errorResponse('Advance not found', 404);
            }

            $perPage = $request->input('per_page', 10);
            $repayments = $advance->repayments()->latest()->paginate($perPage);

            return $this->paginatedResponse($repayments, 'Repayments retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve repayments: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request, $advance_id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        $validator = Validator::make($request->all(), [
            'payment_date' => ['required', 'date'],
            'amount_paid' => ['required', 'numeric', 'min:0.01'],
            'payment_type' => ['required', Rule::in(['Salary Deduction', 'Manual'])],
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }

        DB::beginTransaction();
        try {
            $advance = Advance::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($advance_id);

            if (!$advance) {
                return $this->errorResponse('Advance not found', 404);
            }

            $validatedData = $validator->validated();
            $validatedData['tenant_id'] = $tenantId;
            $validatedData['company_id'] = $activeCompanyId;

            $repayment = $advance->repayments()->create($validatedData);

            $this->advanceStatusService->updateAdvanceStatus($advance);
            DB::commit();

            return $this->successResponse($repayment, 'Advance repayment created successfully', 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to create repayment: ' . $e->getMessage(), 500);
        }
    }

    public function void(Request $request, $repayment_id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        DB::beginTransaction();
        try {
            $repayment = AdvanceRepayment::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($repayment_id);

            if (!$repayment) {
                return $this->errorResponse('Repayment not found', 404);
            }

            if ($repayment->status === 'Voided') {
                return $this->errorResponse('This repayment has already been voided', 409);
            }

            $repayment->status = 'Voided';
            $repayment->save();

            $this->advanceStatusService->updateAdvanceStatus($repayment->advance);
            DB::commit();

            return $this->successResponse($repayment, 'Advance repayment voided successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to void repayment: ' . $e->getMessage(), 500);
        }
    }
}

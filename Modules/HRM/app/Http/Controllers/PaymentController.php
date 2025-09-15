<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\Payment;

class PaymentController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $perPage = $request->input('per_page', 10);
            $search = $request->input('search');

            $query = Payment::with('employee')
                ->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId);

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('type', 'like', "%{$search}%")
                      ->orWhereHas('employee', function ($eq) use ($search) {
                          $eq->where('first_name', 'like', "%{$search}%")
                             ->orWhere('last_name', 'like', "%{$search}%");
                      });
                });
            }

            $payments = $query->latest()->paginate($perPage);

            return $this->paginatedResponse($payments, 'Payments retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve payments: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        $validator = $this->validatePayment($request, 'store', null, $tenantId, $activeCompanyId);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }

        DB::beginTransaction();
        try {
            $validatedData = $validator->validated();
            $validatedData['tenant_id'] = $tenantId;
            $validatedData['company_id'] = $activeCompanyId;

            $payment = Payment::create($validatedData);
            DB::commit();

            return $this->show($request, $payment->id);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to create payment: ' . $e->getMessage(), 500);
        }
    }

    public function show(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $payment = Payment::with('employee')
                ->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($id);

            if (!$payment) {
                return $this->errorResponse('Payment not found', 404);
            }

            return $this->successResponse($payment, 'Payment retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve payment: ' . $e->getMessage(), 500);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        DB::beginTransaction();
        try {
            $payment = Payment::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($id);

            if (!$payment) {
                return $this->errorResponse('Payment not found', 404);
            }

            $validator = $this->validatePayment($request, 'update', $id, $tenantId, $activeCompanyId);

            if ($validator->fails()) {
                return $this->errorResponse($validator->errors()->all(), 422);
            }

            $payment->update($validator->validated());
            DB::commit();

            return $this->show($request, $id);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to update payment: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $payment = Payment::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($id);

            if (!$payment) {
                return $this->errorResponse('Payment not found', 404);
            }

            $payment->delete();

            return $this->successResponse(null, 'Payment deleted successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to delete payment: ' . $e->getMessage(), 500);
        }
    }

    private function validatePayment(Request $request, string $method, ?int $paymentId, string $tenantId, int $companyId)
    {
        $isPost = $method === 'store';
        $required = $isPost ? 'required' : 'sometimes';

        $rules = [
            'employee_id' => [
                $required, 'integer',
                "exists:employees,id,tenant_id,{$tenantId},company_id,{$companyId}"
            ],
            'amount' => [$required, 'numeric', 'min:0'],
            'payment_date' => [$required, 'date'],
            'type' => [$required, Rule::in(['Bonus', 'Reimbursement', 'Other'])],
            'description' => 'nullable|string',
        ];

        return Validator::make($request->all(), $rules);
    }
}

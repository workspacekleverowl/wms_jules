<?php

namespace Modules\Bookkeeping\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\bkorder;
use App\Models\bkorderpayments;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\ApiController;

class OrderpaymentsController extends ApiController
{

    public function purchaseindex(Request $request)
    {

        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Book-Keeping-Supplier-Payment-Show');
        if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;

        try {
            $perPage = $request->input('per_page', 10);
            $page = $request->input('page', 1);
            $query = bkorderpayments::with('bkorder')->where('tenant_id', $Id)->where('company_id', $activeCompanyId)->where('order_type','purchase');
            $search = $request->input('search');

             // Get the authenticated user
             $user = auth()->user();

            if ($search !== null && $search !== '')
            {
                
                $query->where('payment_method', 'like', "%{$search}%")
                ->orWhere('amount', 'like', "%{$search}%")
                ->orWhere('reference_no', 'like', "%{$search}%")
                ->orWhere('remark', 'like', "%{$search}%");
            }

            $query->orderBy('id', 'desc');
            
           

            $OrderPayments = $query->paginate((int)$perPage, ['*'], 'page', (int)$page);
            return $this->paginatedResponse($OrderPayments, 'purchase Order payments retrieved successfully');
        } catch (\Exception $e) {
            return static::errorResponse(['Failed to retrieve  Order payments', $e->getMessage()], 500);
        }
    }

    public function salesindex(Request $request)
    {

        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Book-Keeping-Customer-Payment-Show');
        if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;

        try {
            $perPage = $request->input('per_page', 10);
            $page = $request->input('page', 1);
            $query = bkorderpayments::with('bkorder')->where('tenant_id', $Id)->where('company_id', $activeCompanyId)->where('order_type','sales');
            $search = $request->input('search');

             // Get the authenticated user
             $user = auth()->user();

            if ($search !== null && $search !== '')
            {
                
                $query->where('payment_method', 'like', "%{$search}%")
                ->orWhere('amount', 'like', "%{$search}%")
                ->orWhere('reference_no', 'like', "%{$search}%")
                ->orWhere('remark', 'like', "%{$search}%");
            }

            $query->orderBy('id', 'desc');
            
           

            $OrderPayments = $query->paginate((int)$perPage, ['*'], 'page', (int)$page);
            return $this->paginatedResponse($OrderPayments, 'sales Order payments retrieved successfully');
        } catch (\Exception $e) {
            return static::errorResponse(['Failed to retrieve  Order payments', $e->getMessage()], 500);
        }
    }

    /**
     * Store a newly created payment.
     */
    public function store(Request $request)
    {
        $makePaymentPurchasePermission = $this->checkPermission('Book-Keeping-Purchase-Transactions-Make-Payment');
        $makePaymentSalesPermission = $this->checkPermission('Book-Keeping-Sales-Transactions-Make-Payment');

        // If BOTH permission checks fail (assuming it returns an error response), return it
        // Only if ALL permissions fail, return the error
        if ($makePaymentPurchasePermission && $makePaymentSalesPermission) {
            return $makePaymentPurchasePermission; // or any of the error responses
        }

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $tenantId = $request->user()->tenant_id;

        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:bk_order,id',
            'payment_method' => 'required|in:cash,banktransfer,creditcard,upi,cheque',
            'payment_date' =>'required|date',
            'amount' => 'required|numeric|min:0.01',
            'tds_amount' => 'nullable|numeric|min:0',
            'reference_no' => 'nullable|string|max:255',
            'remark' => 'nullable|string|max:1000'
        ]);

        if ($validator->fails()) {
            return static::errorResponse($validator->errors()->all(), 422);
        }

        DB::beginTransaction();
        try {
            // Verify  order exists and belongs to user
            $Order = bkorder::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($request->order_id);

            if (!$Order) {
                return static::errorResponse(['Invalid  order ID'], 404);
            }

            if ($Order->order_type=="purchaseorder") {
                return static::errorResponse(['Payment cannot be processed for purchase orders. Please select a different order type to proceed with payment.'], 409);
            }

        

           // Get the TDS amount from the request, defaulting to 0 if not provided.
            $tdsAmount = $request->tds_amount ?? 0;
            
            // Calculate the total credit for this specific transaction (payment + TDS).
            $currentTransactionCredit = $request->amount + $tdsAmount;

            // The total amount already paid/credited for this order is stored in `paid_amount`.
            $totalAlreadyCredited = $Order->paid_amount;

            // Calculate the new total credited amount if this payment is processed.
            $newTotalCredited = $totalAlreadyCredited + $currentTransactionCredit;

            // Check if new payment amount would exceed total order amount
            
            if ($newTotalCredited > $Order->total_amount) {
                $remainingAmount = $Order->total_amount - $totalAlreadyCredited;
                return static::errorResponse([
                    'Payment amount exceeds remaining balance',
                    "Total order amount: " . number_format($Order->total_amount, 2),
                    "Already paid: " . number_format($totalAlreadyCredited, 2),
                    "Remaining amount: " . number_format($remainingAmount, 2),
                    "Attempted payment: " . number_format($currentTransactionCredit, 2)
                ], 422);
            }

            // Create payment record
            $payment = bkorderpayments::create([
                'tenant_id' => $tenantId,
                'company_id' => $activeCompanyId,
                'order_id' => $request->order_id,
                'payment_method' => $request->payment_method,
                'payment_date' => $request->payment_date,
                'amount' => $request->amount,
                'tds_amount' => $tdsAmount,
                'reference_no' => $request->reference_no,
                'remark' => $request->remark,
                'order_type'=>$Order->order_type
            ]);

            // Update  order paid amount
            $Order->update([
                'paid_amount' => $newTotalCredited
            ]);

            DB::commit();

            return $this->show($request, $payment->id);

        } catch (\Exception $e) {
            DB::rollBack();
            return static::errorResponse(['Failed to create payment', $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified payment.
     */
    public function show(Request $request, $id)
    {
        $showSupplierPaymentPermission = $this->checkPermission('Book-Keeping-Supplier-Payment-Show');
        $showCustomerPaymentPermission = $this->checkPermission('Book-Keeping-Customer-Payment-Show');

        // If BOTH permission checks fail (assuming it returns an error response), return it
        // Only if ALL permissions fail, return the error
        if ($showSupplierPaymentPermission && $showCustomerPaymentPermission) {
            return $showSupplierPaymentPermission; // or any of the error responses
        }
        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $tenantId = $request->user()->tenant_id;

        try {
            $payment = bkorderpayments::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)->find($id);

            if (!$payment) {
                return static::errorResponse(['Payment not found or access denied'], 404);
            }

            
            // Load the  order relationship manually
            $payment->load('bkorder');

            return static::successResponse([
                'payment' => $payment,
                'order_info' => [
                    'order_no' => $payment->bkorder->order_no,
                    'total_amount' => number_format($payment->bkorder->total_amount, 2, '.', ''),
                    'paid_amount' => number_format($payment->bkorder->paid_amount, 2, '.', '')
                ]
            ], 'Payment retrieved successfully');

        } catch (\Exception $e) {
            return static::errorResponse(['Failed to retrieve payment', $e->getMessage()], 500);
        }
    }

    /**
     * Update the specified payment.
     */
    public function update(Request $request, $id)
    {
        $updateSupplierPaymentPermission = $this->checkPermission('Book-Keeping-Supplier-Payment-Update');
        $updateCustomerPaymentPermission = $this->checkPermission('Book-Keeping-Customer-Payment-Update');

        if ($updateSupplierPaymentPermission && $updateCustomerPaymentPermission) {
            return $updateSupplierPaymentPermission;
        }

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $tenantId = $request->user()->tenant_id;

        $validator = Validator::make($request->all(), [
            'payment_method' => 'sometimes|required|in:cash,banktransfer,creditcard,upi,cheque',
            'payment_date' =>'sometimes|required|date',
            'amount' => 'sometimes|required|numeric|min:0.01',
            'tds_amount' => 'nullable|numeric|min:0', // Added validation for TDS
            'reference_no' => 'nullable|string|max:255',
            'remark' => 'nullable|string|max:1000'
        ]);

        if ($validator->fails()) {
            return static::errorResponse($validator->errors()->all(), 422);
        }

        DB::beginTransaction();
        try {
            // Get the payment being updated
            $payment = bkorderpayments::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)->find($id);

            if (!$payment) {
                return static::errorResponse(['Payment not found or access denied'], 404);
            }

            // Get the associated order
            $Order = bkorder::where('id', $payment->order_id)
                ->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->first();

            if (!$Order) {
                return static::errorResponse(['Order for this payment not found or access denied'], 404);
            }

            // --- TDS LOGIC FOR UPDATE START ---

            // Calculate the credit amount (payment + TDS) of this payment *before* the update.
            $oldCreditForThisPayment = $payment->amount + ($payment->tds_amount ?? 0);

            // Get new amount and TDS from the request, falling back to existing values if not provided.
            $newAmount = $request->filled('amount') ? $request->amount : $payment->amount;
            $newTdsAmount = $request->filled('tds_amount') ? $request->tds_amount : ($payment->tds_amount ?? 0);
            $newCreditForThisPayment = $newAmount + $newTdsAmount;

            // Calculate total credit from all *other* payments by subtracting the old credit from the order's total.
            $otherPaymentsTotalCredit = $Order->paid_amount - $oldCreditForThisPayment;

            // Calculate the new grand total credit for the order.
            $newTotalCredited = $otherPaymentsTotalCredit + $newCreditForThisPayment;

            // Check if the updated payment would exceed the total order amount.
            if ($newTotalCredited > $Order->total_amount) {
                $remainingAmount = $Order->total_amount - $otherPaymentsTotalCredit;
                return static::errorResponse([
                    'Payment amount (including TDS) exceeds remaining balance',
                    "Total order amount: " . number_format($Order->total_amount, 2),
                    "Other payments total credit: " . number_format($otherPaymentsTotalCredit, 2),
                    "Maximum allowed for this payment: " . number_format($remainingAmount, 2),
                    "Attempted credit (amount + TDS): " . number_format($newCreditForThisPayment, 2)
                ], 422);
            }

            // Update payment fields with only the data present in the request.
            $updateData = $request->only([
                'payment_method', 'payment_date', 'amount', 'tds_amount', 'reference_no', 'remark'
            ]);
            $payment->update($updateData);

            // Update order's paid amount with the new calculated total.
            $Order->update([
                'paid_amount' => $newTotalCredited
            ]);

            // --- TDS LOGIC FOR UPDATE END ---

            DB::commit();

            return $this->show($request, $id);

        } catch (\Exception $e) {
            DB::rollBack();
            return static::errorResponse(['Failed to update payment', $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified payment.
     */
    public function destroy(Request $request, $id)
    {

        $deleteSupplierPaymentPermission = $this->checkPermission('Book-Keeping-Supplier-Payment-Delete');
        $deleteCustomerPaymentPermission = $this->checkPermission('Book-Keeping-Customer-Payment-Delete');

        // If BOTH permission checks fail (assuming it returns an error response), return it
        // Only if ALL permissions fail, return the error
        if ($deleteSupplierPaymentPermission && $deleteCustomerPaymentPermission) {
            return $deleteSupplierPaymentPermission; // or any of the error responses
        }
        
        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $tenantId = $request->user()->tenant_id;

        DB::beginTransaction();
        try {
             $payment = bkorderpayments::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)->find($id);

            if (!$payment) {
                return static::errorResponse(['Payment not found or access denied'], 404);
            }

            // Then verify the  order belongs to the user
            $Order = bkorder::where('id', $payment->order_id)
                ->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->first();

            if (!$Order) {
                return static::errorResponse([' Order for this payment not found or access denied'], 404);
            }

            $paymentAmount = $payment->amount;

            // Delete the payment
            $payment->delete();

            // Recalculate and update  order paid amount
            $newTotalPaid = bkorderpayments::where('order_id', $Order->id)
                ->sum('amount');

            $Order->update([
                'paid_amount' => $newTotalPaid
            ]);

            DB::commit();

            return static::successResponse(null, 'Payment deleted successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            return static::errorResponse(['Failed to delete payment', $e->getMessage()], 500);
        }
    }


    /**
     * Get payment summary for a  order
     */
   public function getPaymentSummary(Request $request, $OrderId) 
    {
        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $tenantId = $request->user()->tenant_id;

        try {
            // Verify  order exists and belongs to user
            $Order = bkorder::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($OrderId);

            if (!$Order) {
                return static::errorResponse(['Invalid  order ID'], 404);
            }

            // Get all individual payments
            $allPayments = bkorderpayments::where('order_id', $OrderId)
                ->orderBy('created_at', 'desc')
                ->get();

            $totalPaidorderamount = bkorderpayments::where('order_id', $OrderId)
                ->sum('amount');

            $totalPaidtdsamount = bkorderpayments::where('order_id', $OrderId)
                ->sum('tds_amount');
                
            $totalPaid =$totalPaidorderamount + $totalPaidtdsamount ;    
                        
            $remainingAmount = $Order->total_amount - $totalPaid;

            $summary = [
                'order_no' => $Order->order_no,
                'total_order_amount' => number_format($Order->total_amount, 2, '.', ''),
                'total_paid_amount' => number_format($totalPaid, 2, '.', ''),
                'remaining_amount' => number_format($remainingAmount, 2, '.', ''),
                'payment_status' => $remainingAmount <= 0 ? 'Fully Paid' : ($totalPaid > 0 ? 'Partially Paid' : 'Unpaid'),
                'payments' => $allPayments->map(function($payment) {
                    return [
                        'id' => $payment->id,
                        'payment_method' => $payment->payment_method,
                        'method_name' => bkorderpayments::PAYMENT_METHODS[$payment->payment_method] ?? $payment->payment_method,
                        'amount' => number_format($payment->amount, 2, '.', ''),
                        'tds_amount' => number_format($payment->tds_amount, 2, '.', ''),
                        'payment_date' => $payment->payment_date,
                        'reference_number' => $payment->reference_no,
                        'remark' => $payment->remark,
                        'created_at' => $payment->created_at
                    ];
                })
            ];

            return static::successResponse($summary, 'Payment summary retrieved successfully');

        } catch (\Exception $e) {
            return static::errorResponse(['Failed to retrieve payment summary', $e->getMessage()], 500);
        }
    }
    
    
     
}

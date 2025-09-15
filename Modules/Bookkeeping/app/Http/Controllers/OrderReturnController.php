<?php

namespace Modules\Bookkeeping\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\bkorder;
use App\Models\bkorderlineitems;
use App\Models\bkorderreturn;
use App\Models\bkorderreturnlineitems;
use App\Models\OrderStockSummary;
use App\Models\company;

class OrderReturnController extends ApiController
{
      /**
     * Get stock summary for an order to check available quantities for return
     */
    public function getOrderStockSummary(Request $request, $orderId)
    {
        $authHeader = $request->header('Authorization');
        $showPurchaseReturnPermission = $this->checkPermission('Book-Keeping-Purchase-Return-Show');
        $showSalesReturnPermission = $this->checkPermission('Book-Keeping-Sales-Return-Show');

        // If BOTH permission checks fail (assuming it returns an error response), return it
        // Only if ALL permissions fail, return the error
        if ($showPurchaseReturnPermission && $showSalesReturnPermission) {
            return $showPurchaseReturnPermission; // or any of the error responses
        }
        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $tenantId = $request->user()->tenant_id;

        try {
            // Verify order exists and belongs to user
            $order = bkorder::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($orderId);

            if (!$order) {
                return static::errorResponse(['Invalid order ID'], 404);
            }

            // Check if order type allows returns
            if ($order->order_type === 'purchaseorder') {
                return static::errorResponse(['Returns are not allowed for purchase orders'], 400);
            }

            // Get stock summary from view
            $stockSummary = OrderStockSummary::forTenantCompany($tenantId, $activeCompanyId)
                ->where('order_id', $orderId)
                ->availableForReturn()
                ->get();

            if ($stockSummary->isEmpty()) {
                return static::errorResponse(['No items available for return in this order'], 404);
            }

            return static::successResponse([
                'order' => [
                    'id' => $order->id,
                    'order_no' => $order->order_no,
                    'order_date' => $order->order_date,
                    'order_type' => $order->order_type,
                    'total_amount' => $order->total_amount
                ],
                'available_items' => $stockSummary
            ], 'Order stock summary retrieved successfully');

        } catch (\Exception $e) {
            return static::errorResponse(['Failed to retrieve order stock summary', $e->getMessage()], 500);
        }
    }

    /**
     * Display a listing of order returns
     */
    public function purchasereturnindex(Request $request)
    {
        $permissionResponse = $this->checkPermission('Book-Keeping-Purchase-Return-Show');
        if ($permissionResponse) return $permissionResponse;
        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $tenantId = $request->user()->tenant_id;

        try {
            $perPage = $request->input('per_page', 10);
            $page = $request->input('page', 1);
            
            $query = bkorderreturn::with(['bkorder', 'bkorderreturnlineitems'])
                ->forTenantCompany($tenantId, $activeCompanyId)->where('order_type','purchase');

            if ($request->filled('date_from') && $request->filled('date_to')) {
                $query->whereBetween('return_date', [
                    $request->input('date_from'),
                    $request->input('date_to')
                ]);
            }

            // Search functionality
            $search = $request->input('search');
            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('return_no', 'like', "%{$search}%")
                      ->orWhere('return_reason', 'like', "%{$search}%")
                      ->orWhereHas('bkorder', function($orderQuery) use ($search) {
                          $orderQuery->where('order_no', 'like', "%{$search}%");
                      });
                });
            }

            $query->orderBy('id', 'desc');
            $returns = $query->paginate((int)$perPage, ['*'], 'page', (int)$page);

            return $this->paginatedResponse($returns, 'purchase Order returns retrieved successfully');

        } catch (\Exception $e) {
            return static::errorResponse(['Failed to retrieve order returns', $e->getMessage()], 500);
        }
    }

    public function salesreturnindex(Request $request)
    {
        $permissionResponse = $this->checkPermission('Book-Keeping-Sales-Return-Show');
        if ($permissionResponse) return $permissionResponse;
        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $tenantId = $request->user()->tenant_id;

        try {
            $perPage = $request->input('per_page', 10);
            $page = $request->input('page', 1);
            
            $query = bkorderreturn::with(['bkorder', 'bkorderreturnlineitems'])
                ->forTenantCompany($tenantId, $activeCompanyId)->where('order_type','sales');

            if ($request->filled('date_from') && $request->filled('date_to')) {
                $query->whereBetween('return_date', [
                    $request->input('date_from'),
                    $request->input('date_to')
                ]);
            }

            // Search functionality
            $search = $request->input('search');
            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('return_no', 'like', "%{$search}%")
                      ->orWhere('return_reason', 'like', "%{$search}%")
                      ->orWhereHas('bkorder', function($orderQuery) use ($search) {
                          $orderQuery->where('order_no', 'like', "%{$search}%");
                      });
                });
            }

            $query->orderBy('id', 'desc');
            $returns = $query->paginate((int)$perPage, ['*'], 'page', (int)$page);

            return $this->paginatedResponse($returns, 'sales Order returns retrieved successfully');

        } catch (\Exception $e) {
            return static::errorResponse(['Failed to retrieve order returns', $e->getMessage()], 500);
        }
    }


    /**
     * Store a newly created order return
     */
    public function store(Request $request)
    {
        $returnPurchasePermission = $this->checkPermission('Book-Keeping-Purchase-Transactions-Return');
        $returnSalesPermission = $this->checkPermission('Book-Keeping-Sales-Transactions-Return');

        // If BOTH permission checks fail (assuming it returns an error response), return it
        // Only if ALL permissions fail, return the error
        if ($returnPurchasePermission && $returnSalesPermission) {
            return $returnPurchasePermission; // or any of the error responses
        }
        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $tenantId = $request->user()->tenant_id;

        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:bk_order,id',
            'return_no' => 'nullable|string|max:255',
            'return_date' => 'required|date',
            'return_reason' => 'nullable|string',
            'line_items' => 'required|array|min:1',
            'line_items.*.order_lineitem_id' => 'required|exists:bk_order_lineitems,id',
            'line_items.*.return_quantity' => 'required|numeric|min:0.001'
        ]);

        if ($validator->fails()) {
            return static::errorResponse($validator->errors()->all(), 422);
        }

        DB::beginTransaction();
        try {
            // Verify order exists and belongs to user
            $order = bkorder::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($request->order_id);

            if (!$order) {
                return static::errorResponse(['Invalid order ID'], 404);
            }

            // Check if order type allows returns
            if ($order->order_type === 'purchaseorder') {
                return static::errorResponse(['Returns are not allowed for purchase orders'], 400);
            }

            // Validate each line item and check available quantities
            $totalReturnAmount = 0;
            $validatedLineItems = [];

            foreach ($request->line_items as $index => $item) {
                // Get stock summary for this line item
                $stockInfo = OrderStockSummary::forTenantCompany($tenantId, $activeCompanyId)
                    ->where('order_lineitem_id', $item['order_lineitem_id'])
                    ->where('order_id',$order->id)
                    ->first();

                if (!$stockInfo) {
                    return static::errorResponse([
                        "Line item {$index} not found or doesn't belong to this order"
                    ], 422);
                }

                // Check if return quantity is valid
                if ($item['return_quantity'] > $stockInfo->available_for_return) {
                    return static::errorResponse([
                        "Return quantity for '{$stockInfo->product_name}' exceeds available quantity.",
                        "Available: {$stockInfo->available_for_return}, Requested: {$item['return_quantity']}"
                    ], 422);
                }

                // Calculate return amounts
                $returnAmount = $stockInfo->rate * $item['return_quantity'];
                 // Apply line item discount if provided
                $lineItemDiscount = $stockInfo->line_item_discount?? 0;
                $lineItemDiscountAmount = ($returnAmount * $lineItemDiscount) / 100;
                $amountAfterLineDiscount = $returnAmount - $lineItemDiscountAmount;
                
                $gstValue = ($amountAfterLineDiscount * $stockInfo->gst_rate) / 100;
                $returnAmountWithGst = $amountAfterLineDiscount + $gstValue;
                $totalReturnAmount += $returnAmountWithGst;

                $validatedLineItems[] = [
                    'order_lineitem_id' => $item['order_lineitem_id'],
                    'product_name' => $stockInfo->product_name,
                    'product_description' => $stockInfo->product_description,
                    'product_hsn' => $stockInfo->product_hsn,
                    'return_quantity' => $item['return_quantity'],
                    'original_quantity' => $stockInfo->original_quantity,
                    'rate' => $stockInfo->rate,
                    'gst_rate' => $stockInfo->gst_rate,
                    'gst_value' => $gstValue,
                    'unit' => $stockInfo->unit,
                    'amount' => $returnAmount,
                    'line_item_discount' => $lineItemDiscount,
                    'line_item_discount_amount' => $lineItemDiscountAmount,
                    'amount_after_line_discount' => $amountAfterLineDiscount,
                    'amount_with_gst' => $returnAmountWithGst,
                ];
            }

            // Generate financial year
            $financialYear = $this->getFinancialYear($request->return_date);

            // Create return record
            $orderReturn = bkorderreturn::create([
                'tenant_id' => $tenantId,
                'company_id' => $activeCompanyId,
                'order_id' => $request->order_id,
                'return_no' => $request->return_no,
                'return_date' => $request->return_date,
                'financial_year' => $financialYear,
                'return_reason' => $request->return_reason,
                'total_return_amount' => $totalReturnAmount,
                'order_type' =>$order->order_type
            ]);

            // Create return line items
            foreach ($validatedLineItems as $lineItem) {
                bkorderreturnlineitems::create(array_merge($lineItem, [
                    'return_id' => $orderReturn->id
                ]));
            }

            DB::commit();

            return $this->show($request, $orderReturn->id);

        } catch (\Exception $e) {
            DB::rollBack();
            return static::errorResponse(['Failed to create order return', $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified order return
     */
    public function show(Request $request, $id)
    {
        $showPurchaseReturnPermission = $this->checkPermission('Book-Keeping-Purchase-Return-Show');
        $showSalesReturnPermission = $this->checkPermission('Book-Keeping-Sales-Return-Show');

        // If BOTH permission checks fail, return the error
        if ($showPurchaseReturnPermission && $showSalesReturnPermission) {
            return $showPurchaseReturnPermission; // or any of the error responses
        }

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $tenantId = $request->user()->tenant_id;

        try {
            $orderReturn = bkorderreturn::with(['bkorder', 'bkorderreturnlineitems'])
                ->forTenantCompany($tenantId, $activeCompanyId)
                ->find($id);

            if (!$orderReturn) {
                return static::errorResponse(['Order return not found'], 404);
            }

            $company = company::with('state')->where('id', $activeCompanyId)
                                            ->where('tenant_id',$tenantId)
                                            ->first();
            
            if (!$company) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Company not found',
                ], 422);
            }

            // Calculate GST summary
            $isLocal = ($orderReturn->bkorder->company_state_id_billing === $company->state->id);
            $totalCgst = 0;
            $totalSgst = 0;
            $totalIgst = 0;
            $gstSummary = [];

            foreach ($orderReturn->bkorderreturnlineitems as $item) {
                $gstRate = $item->gst_rate;
                // **MODIFIED**: Use amount_after_line_discount as the taxable base
                $taxableAmount = $item->amount_after_line_discount; 
                $gstAmount = ($taxableAmount * $gstRate) / 100;

                if ($isLocal) {
                    $cgst = $gstAmount / 2;
                    $sgst = $gstAmount / 2;
                    $totalCgst += $cgst;
                    $totalSgst += $sgst;
                } else {
                    $igst = $gstAmount;
                    $totalIgst += $igst;
                }

                if (!isset($gstSummary[$gstRate])) {
                    $gstSummary[$gstRate] = [
                        'taxable_amount' => 0,
                        'cgst' => 0,
                        'sgst' => 0,
                        'igst' => 0,
                        'total_gst' => 0,
                        'total_with_gst' => 0
                    ];
                }

                $gstSummary[$gstRate]['taxable_amount'] += $taxableAmount;

                if ($isLocal) {
                    $gstSummary[$gstRate]['cgst'] += $cgst;
                    $gstSummary[$gstRate]['sgst'] += $sgst;
                    $gstSummary[$gstRate]['total_gst'] += $cgst + $sgst;
                } else {
                    $gstSummary[$gstRate]['igst'] += $igst;
                    $gstSummary[$gstRate]['total_gst'] += $igst;
                }

                $gstSummary[$gstRate]['total_with_gst'] = $gstSummary[$gstRate]['taxable_amount'] + $gstSummary[$gstRate]['total_gst'];
            }

            $totalTaxableAmount = array_sum(array_column($gstSummary, 'taxable_amount'));
            $totalGstAmount = array_sum(array_column($gstSummary, 'total_gst'));
            $totalWithGst = $totalTaxableAmount + $totalGstAmount;

            $roundedTotalWithGst = round($totalWithGst);
            $roundOff = round($roundedTotalWithGst - $totalWithGst, 2);

            $returnGst = [
                'total_taxable_amount' => number_format($totalTaxableAmount, 2, '.', ''),
                'total_cgst' => $isLocal ? number_format($totalCgst, 2, '.', '') : null,
                'total_sgst' => $isLocal ? number_format($totalSgst, 2, '.', '') : null,
                'total_igst' => !$isLocal ? number_format($totalIgst, 2, '.', '') : null,
                'total_gst' => number_format($totalGstAmount, 2, '.', ''),
                'total_with_gst' => number_format($roundedTotalWithGst, 2, '.', ''),
                'round_off' => number_format($roundOff, 2, '.', '')
            ];

            // HSN wise summary
            $hsnWiseSummary = [];

            foreach ($orderReturn->bkorderreturnlineitems as $item) {
                $hsn = $item->product_hsn;
                $gstRate = $item->gst_rate;
                 // **MODIFIED**: Use amount_after_line_discount as the taxable base
                $taxableAmount = $item->amount_after_line_discount;
                $gstAmount = ($taxableAmount * $gstRate) / 100;

                if ($isLocal) {
                    $cgst = $gstAmount / 2;
                    $sgst = $gstAmount / 2;
                } else {
                    $igst = $gstAmount;
                }

                if (!isset($hsnWiseSummary[$hsn])) {
                    $hsnWiseSummary[$hsn] = [
                        'taxable_amount' => 0,
                        'cgst' => 0,
                        'sgst' => 0,
                        'igst' => 0,
                        'total_gst' => 0,
                        'total_with_gst' => 0,
                        'cgst_rate' => 0,
                        'sgst_rate' => 0,
                        'igst_rate' => 0
                    ];
                }

                $hsnWiseSummary[$hsn]['taxable_amount'] += $taxableAmount;

                if ($isLocal) {
                    $hsnWiseSummary[$hsn]['cgst'] += $cgst;
                    $hsnWiseSummary[$hsn]['sgst'] += $sgst;
                    $hsnWiseSummary[$hsn]['total_gst'] += $cgst + $sgst;

                    $hsnWiseSummary[$hsn]['cgst_rate'] = $gstRate / 2;
                    $hsnWiseSummary[$hsn]['sgst_rate'] = $gstRate / 2;
                    $hsnWiseSummary[$hsn]['igst_rate'] = 0;
                } else {
                    $hsnWiseSummary[$hsn]['igst'] += $igst;
                    $hsnWiseSummary[$hsn]['total_gst'] += $igst;

                    $hsnWiseSummary[$hsn]['cgst_rate'] = 0;
                    $hsnWiseSummary[$hsn]['sgst_rate'] = 0;
                    $hsnWiseSummary[$hsn]['igst_rate'] = $gstRate;
                }

                $hsnWiseSummary[$hsn]['total_with_gst'] = $hsnWiseSummary[$hsn]['taxable_amount'] + $hsnWiseSummary[$hsn]['total_gst'];
            }

            // Format hsnWiseSummary
            foreach ($hsnWiseSummary as &$hsn) {
                $hsn['taxable_amount'] = number_format($hsn['taxable_amount'], 2, '.', '');
                $hsn['cgst'] = number_format($hsn['cgst'], 2, '.', '');
                $hsn['sgst'] = number_format($hsn['sgst'], 2, '.', '');
                $hsn['igst'] = number_format($hsn['igst'], 2, '.', '');
                $hsn['total_gst'] = number_format($hsn['total_gst'], 2, '.', '');
                $hsn['total_with_gst'] = number_format($hsn['total_with_gst'], 2, '.', '');
                $hsn['cgst_rate'] = number_format($hsn['cgst_rate'], 2, '.', '');
                $hsn['sgst_rate'] = number_format($hsn['sgst_rate'], 2, '.', '');
                $hsn['igst_rate'] = number_format($hsn['igst_rate'], 2, '.', '');
            }

            return static::successResponse([
                'order_return' => $orderReturn,
                'return_gst' => array_filter($returnGst),
                'gst_summary' => $gstSummary,
                'hsnwisesummary' => $hsnWiseSummary,
            ], 'Order return details retrieved successfully');

        } catch (\Exception $e) {
            return static::errorResponse(['Failed to retrieve order return', $e->getMessage()], 500);
        }
    }
    

    
    /**
     * Update an existing order return
     */
    public function update(Request $request, $id)
    {
        $updatePurchaseReturnPermission = $this->checkPermission('Book-Keeping-Purchase-Return-Update');
        $updateSalesReturnPermission = $this->checkPermission('Book-Keeping-Sales-Return-Update');

        // If BOTH permission checks fail (assuming it returns an error response), return it
        // Only if ALL permissions fail, return the error
        if ($updatePurchaseReturnPermission && $updateSalesReturnPermission) {
            return $updatePurchaseReturnPermission; // or any of the error responses
        }

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $tenantId = $request->user()->tenant_id;

        $validator = Validator::make($request->all(), [
            'return_no' => 'sometimes|required|string|max:255',
            'return_date' => 'sometimes|required|date',
            'return_reason' => 'nullable|string',
            'line_items' => 'sometimes|required|array|min:1',
            'line_items.*.order_lineitem_id' => 'required_with:line_items|exists:bk_order_lineitems,id',
            'line_items.*.return_quantity' => 'required_with:line_items|numeric|min:0.001',
            'line_items.*.return_reason' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return static::errorResponse($validator->errors()->all(), 422);
        }

        DB::beginTransaction();
        try {
            // Find the order return
            $orderReturn = bkorderreturn::with(['bkorder', 'bkorderreturnlineitems'])
                ->forTenantCompany($tenantId, $activeCompanyId)
                ->find($id);

            if (!$orderReturn) {
                return static::errorResponse(['Order return not found'], 404);
            }

            // If line items are being updated, validate them
            if ($request->has('line_items')) {
                $totalReturnAmount = 0;
                $validatedLineItems = [];

                foreach ($request->line_items as $index => $item) {
                    // Get stock summary for this line item
                    $stockInfo = OrderStockSummary::forTenantCompany($tenantId, $activeCompanyId)
                        ->where('order_lineitem_id', $item['order_lineitem_id'])
                        ->where('order_id', $orderReturn->order_id)
                        ->first();

                    if (!$stockInfo) {
                        return static::errorResponse([
                            "Line item {$index} not found or doesn't belong to this order"
                        ], 422);
                    }

                    // Calculate available quantity including current return quantities
                    $currentReturnQty = $orderReturn->bkorderreturnlineitems()
                        ->where('order_lineitem_id', $item['order_lineitem_id'])
                        ->sum('return_quantity');
                    
                    $availableForReturn = $stockInfo->available_for_return + $currentReturnQty;

                    // Check if return quantity is valid
                    if ($item['return_quantity'] > $availableForReturn) {
                        return static::errorResponse([
                            "Return quantity for '{$stockInfo->product_name}' exceeds available quantity.",
                            "Available: {$availableForReturn}, Requested: {$item['return_quantity']}"
                        ], 422);
                    }

                    // Calculate return amounts
                    $returnAmount = $stockInfo->rate * $item['return_quantity'];
                     // Apply line item discount if provided
                    $lineItemDiscount = $stockInfo->line_item_discount?? 0;
                    $lineItemDiscountAmount = ($returnAmount * $lineItemDiscount) / 100;
                    $amountAfterLineDiscount = $returnAmount - $lineItemDiscountAmount;
                    $gstValue = ($amountAfterLineDiscount * $stockInfo->gst_rate) / 100;
                    $returnAmountWithGst = $amountAfterLineDiscount + $gstValue;
                    $totalReturnAmount += $returnAmountWithGst;

                    $validatedLineItems[] = [
                        'order_lineitem_id' => $item['order_lineitem_id'],
                        'product_name' => $stockInfo->product_name,
                        'product_description' => $stockInfo->product_description,
                        'product_hsn' => $stockInfo->product_hsn,
                        'return_quantity' => $item['return_quantity'],
                        'original_quantity' => $stockInfo->original_quantity,
                        'rate' => $stockInfo->rate,
                        'gst_rate' => $stockInfo->gst_rate,
                        'gst_value' => $gstValue,
                        'unit' => $stockInfo->unit,
                        'amount' => $returnAmount,
                        'line_item_discount' => $lineItemDiscount,
                        'line_item_discount_amount' => $lineItemDiscountAmount,
                        'amount_after_line_discount' => $amountAfterLineDiscount,
                        'amount_with_gst' => $returnAmountWithGst,
                    ];
                }

                // Delete existing line items
                $orderReturn->bkorderreturnlineitems()->delete();

                // Create new line items
                foreach ($validatedLineItems as $lineItem) {
                    bkorderreturnlineitems::create(array_merge($lineItem, [
                        'return_id' => $orderReturn->id
                    ]));
                }
            }

            // Prepare fields to update
            $fieldsToUpdate = [];

            if ($request->filled('return_no') || $request->return_no === null || $request->return_no === '') {
                $fieldsToUpdate['return_no'] = $request->return_no;
            }

            if ($request->filled('return_date') || $request->return_date === null || $request->return_date === '') {
                $fieldsToUpdate['return_date'] = $request->return_date;
                if ($request->return_date) {
                    $fieldsToUpdate['financial_year'] = $this->getFinancialYear($request->return_date);
                }
            }

            if ($request->filled('return_reason') || $request->return_reason === null || $request->return_reason === '') {
                $fieldsToUpdate['return_reason'] = $request->return_reason;
            }

            

            // Update total amount if line items were changed
            if ($request->has('line_items')) {
                $fieldsToUpdate['total_return_amount'] = $totalReturnAmount;
            }

            // Update the order return if there are fields to update
            if (!empty($fieldsToUpdate)) {
                $orderReturn->update($fieldsToUpdate);
            }

            DB::commit();

            return $this->show($request, $orderReturn->id);

        } catch (\Exception $e) {
            DB::rollBack();
            return static::errorResponse(['Failed to update order return', $e->getMessage()], 500);
        }
    }

    /**
     * Delete order return (only if status is pending)
     */
    public function destroy(Request $request, $id)
    {
        $deletePurchaseReturnPermission = $this->checkPermission('Book-Keeping-Purchase-Return-Delete');
        $deleteSalesReturnPermission = $this->checkPermission('Book-Keeping-Sales-Return-Delete');

        // If BOTH permission checks fail (assuming it returns an error response), return it
        // Only if ALL permissions fail, return the error
        if ($deletePurchaseReturnPermission && $deleteSalesReturnPermission) {
            return $deletePurchaseReturnPermission; // or any of the error responses
        }
        
        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $tenantId = $request->user()->tenant_id;

        DB::beginTransaction();
        try {
            $orderReturn = bkorderreturn::forTenantCompany($tenantId, $activeCompanyId)
                ->find($id);

            if (!$orderReturn) {
                return static::errorResponse(['Order return not found'], 404);
            }


            // Delete line items first (cascade should handle this, but explicit is better)
            $orderReturn->bkorderreturnlineitems()->delete();
            
            // Delete the return
            $orderReturn->delete();

            DB::commit();

            return static::successResponse(null, 'Order return deleted successfully');

        } catch (\Exception $e) {
            DB::rollBack();
             if ($e instanceof \Illuminate\Database\QueryException) {
                // Check for foreign key constraint error codes
                if ($e->getCode() == 23000 || strpos($e->getMessage(), 'Integrity constraint violation') !== false || strpos($e->getMessage(), 'foreign key constraint fails') !== false) {
                    return response()->json([
                        'status' => 409,
                        'message' => 'Cannot delete this record because there are linked records associated with it. Please remove all related data first.',
                    ], 200);
                }
            }
            return static::errorResponse(['Failed to delete order return', $e->getMessage()], 500);
        }
    }

    private function getFinancialYear(string $date): string
    {
        $dateObj = Carbon::createFromFormat('Y-m-d', $date);
        $year = $dateObj->year;
        $month = $dateObj->month;
        
        // If date is between January and March, it's part of the previous financial year
        if ($month >= 1 && $month <= 3) {
            $startYear = $year - 1;
        } else {
            $startYear = $year;
        }
        
        $endYear = $startYear + 1;
        
        // Format as YY-YY
        return substr($startYear, -2) . '-' . substr($endYear, -2);
    }

}

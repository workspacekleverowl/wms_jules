<?php

namespace Modules\Bookkeeping\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Models\bkorder;
use App\Models\bkorderlineitems;
use App\Models\State;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Http;
use App\Models\party;
use App\Models\bksupplier;
use Illuminate\Validation\Rule;
use App\Models\company;
use App\Models\usersettings;
use Dompdf\Dompdf;
use Dompdf\Options;

class OrderController extends ApiController
{
    public function getData(Request $request)
    {
        try {
            $authHeader = $request->header('Authorization');
           
            $user = $request->user();
            $activeCompanyId = $user->getActiveCompanyId();
            $tenantId = $request->user()->tenant_id;

            // Get search parameter
            $search = $request->input('search', '');

            // Get all customers with optional search
            $customersQuery = party::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->where('is_billable', 'true')
                ->whereNull('deleted_at')
                ->select('id', 'name','address1','address2','city','state_id','pincode','phone','email','gst_number');

            // Apply search filter if search term is provided
            if (!empty($search)) {
                $customersQuery->where('name', 'LIKE', '%' . $search . '%');
            }

            $customers = $customersQuery->orderBy('name', 'asc')->get();

            // Get all suppliers with optional search
            $suppliersQuery = bksupplier::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->whereNull('deleted_at')
                ->select('id', 'name','address1','address2','city','state_id','pincode','phone','email','gst_number');

            // Apply search filter if search term is provided
            if (!empty($search)) {
                $suppliersQuery->where('name', 'LIKE', '%' . $search . '%');
            }

            $suppliers = $suppliersQuery->orderBy('name', 'asc')->get();

            // Return response
            return static::successResponse([
                    'customers' => $customers,
                    'suppliers' => $suppliers
                ], 'Data retrieved successfully');
        

        } catch (\Exception $e) {
            return static::errorResponse(['Failed to retrieve Data', $e->getMessage()], 500);
        }
    }
        


    public function purchaseindex(Request $request)
    {

        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Book-Keeping-Purchase-Transactions-Show');
        if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;

        try {
            $perPage = $request->input('per_page', 10);
            $page = $request->input('page', 1);
            $query = bkorder::with('bkorderlineitems')->where('tenant_id', $Id)->where('company_id', $activeCompanyId)->where('order_type','purchase')->orderBy('order_date', 'desc');
            $search = $request->input('search');

             // Get the authenticated user
             $user = auth()->user();

          
            if ($request->filled('date_from') && $request->filled('date_to')) {
                $query->whereBetween('order_date', [
                    $request->input('date_from'),
                    $request->input('date_to')
                ]);
            }

            $partyID=$request->input('party_id');
            if ($request->has('party_id') && !empty($request->party_id)) {
                $query->where('party_id',$request->party_id);
            }

           // Payment status filter
            $paymentStatus = $request->input('payment_status');
            if ($paymentStatus !== null && $paymentStatus !== '') {
                switch (strtolower($paymentStatus)) {
                    case 'paid':
                        // Fully paid: paid_amount >= total_amount
                        $query->whereRaw('COALESCE(paid_amount, 0) >= total_amount');
                        break;
                        
                    case 'unpaid':
                        // Unpaid: paid_amount is 0 or null
                        $query->whereRaw('COALESCE(paid_amount, 0) <= 0');
                        break;
                        
                    case 'partially_paid':
                        // Partially paid: 0 < paid_amount < total_amount
                        $query->whereRaw('COALESCE(paid_amount, 0) > 0 AND COALESCE(paid_amount, 0) < total_amount');
                        break;
                }
            }


            

            if ($search !== null && $search !== '')
            {
                
                $query->where('order_no', 'like', "%{$search}%")
                ->orWhere('client_name', 'like', "%{$search}%")
                ->orWhere('client_address', 'like', "%{$search}%")
                ->orWhere('client_contact_number', 'like', "%{$search}%")
                ->orWhere('client_email', 'like', "%{$search}%")
                ->orWhere('company_name_billing', 'like', "%{$search}%") 
                ->orWhere('company_address_billing', 'like', "%{$search}%")
                ->orWhere('company_city_billing', 'like', "%{$search}%");
            }

            $query->orderBy('id', 'desc');
            
           

            $Order = $query->paginate((int)$perPage, ['*'], 'page', (int)$page);
            return $this->paginatedResponse($Order, 'purchase retrieved successfully');
        } catch (\Exception $e) {
            return static::errorResponse(['Failed to retrieve  Order', $e->getMessage()], 500);
        }
    }

    public function purchaseOrderindex(Request $request)
    {

        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Book-Keeping-Purchase-Order-Show');
        if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;

        try {
            $perPage = $request->input('per_page', 10);
            $page = $request->input('page', 1);
            $query = bkorder::with('bkorderlineitems')->where('tenant_id', $Id)->where('company_id', $activeCompanyId)->where('order_type','purchaseorder')->orderBy('order_date', 'desc');
            $search = $request->input('search');

             // Get the authenticated user
             $user = auth()->user();

          
            if ($request->filled('date_from') && $request->filled('date_to')) {
                $query->whereBetween('order_date', [
                    $request->input('date_from'),
                    $request->input('date_to')
                ]);
            }

           $partyID=$request->input('party_id');
            if ($request->has('party_id') && !empty($request->party_id)) {
                $query->where('party_id',$request->party_id);
            }

            

            if ($search !== null && $search !== '')
            {
                
                $query->where('order_no', 'like', "%{$search}%")
                ->orWhere('client_name', 'like', "%{$search}%")
                ->orWhere('client_address', 'like', "%{$search}%")
                ->orWhere('client_contact_number', 'like', "%{$search}%")
                ->orWhere('client_email', 'like', "%{$search}%")
                ->orWhere('company_name_billing', 'like', "%{$search}%") 
                ->orWhere('company_address_billing', 'like', "%{$search}%")
                ->orWhere('company_city_billing', 'like', "%{$search}%");
            }

            $query->orderBy('id', 'desc');
            
           

            $Order = $query->paginate((int)$perPage, ['*'], 'page', (int)$page);
            return $this->paginatedResponse($Order, 'Purchase Order retrieved successfully');
        } catch (\Exception $e) {
            return static::errorResponse(['Failed to retrieve  Order', $e->getMessage()], 500);
        }
    }


    public function salesindex(Request $request)
    {

        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Book-Keeping-Sales-Transactions-Show');
        if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;

        try {
            $perPage = $request->input('per_page', 10);
            $page = $request->input('page', 1);
            $query = bkorder::with('bkorderlineitems')->where('tenant_id', $Id)->where('company_id', $activeCompanyId)->where('order_type','sales')->orderBy('order_date', 'desc');
            $search = $request->input('search');

             // Get the authenticated user
             $user = auth()->user();

          
            if ($request->filled('date_from') && $request->filled('date_to')) {
                $query->whereBetween('order_date', [
                    $request->input('date_from'),
                    $request->input('date_to')
                ]);
            }

            $partyID=$request->input('party_id');
            if ($request->has('party_id') && !empty($request->party_id)) {
                $query->where('party_id',$request->party_id);
            }


           // Payment status filter
            $paymentStatus = $request->input('payment_status');
            if ($paymentStatus !== null && $paymentStatus !== '') {
                switch (strtolower($paymentStatus)) {
                    case 'paid':
                        // Fully paid: paid_amount >= total_amount
                        $query->whereRaw('COALESCE(paid_amount, 0) >= total_amount');
                        break;
                        
                    case 'unpaid':
                        // Unpaid: paid_amount is 0 or null
                        $query->whereRaw('COALESCE(paid_amount, 0) <= 0');
                        break;
                        
                    case 'partially_paid':
                        // Partially paid: 0 < paid_amount < total_amount
                        $query->whereRaw('COALESCE(paid_amount, 0) > 0 AND COALESCE(paid_amount, 0) < total_amount');
                        break;
                }
            }
            

            if ($search !== null && $search !== '')
            {
                
                $query->where('order_no', 'like', "%{$search}%")
                ->orWhere('client_name', 'like', "%{$search}%")
                ->orWhere('client_address', 'like', "%{$search}%")
                ->orWhere('client_contact_number', 'like', "%{$search}%")
                ->orWhere('client_email', 'like', "%{$search}%")
                ->orWhere('company_name_billing', 'like', "%{$search}%") 
                ->orWhere('company_address_billing', 'like', "%{$search}%")
                ->orWhere('company_city_billing', 'like', "%{$search}%");
            }

            $query->orderBy('id', 'desc');
            
           

            $Order = $query->paginate((int)$perPage, ['*'], 'page', (int)$page);
            return $this->paginatedResponse($Order, 'sales retrieved successfully');
        } catch (\Exception $e) {
            return static::errorResponse(['Failed to retrieve  Order', $e->getMessage()], 500);
        }
    }

   
    public function store(Request $request)
    {
        $authHeader = $request->header('Authorization');
        $insertTransactionPermission = $this->checkPermission('Book-Keeping-Purchase-Transactions-Insert');
        $insertOrderPermission = $this->checkPermission('Book-Keeping-Purchase-Order-Insert');
        $insertSalesPermission = $this->checkPermission('Book-Keeping-Sales-Transactions-Insert');

        // If ANY permission check fails (assuming it returns an error response), return it
        // Only if ALL permissions fail, return the error
        if ($insertTransactionPermission && $insertOrderPermission && $insertSalesPermission) {
            return $insertTransactionPermission; // or any of the error responses
        }
        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;

        $validator = Validator::make($request->all(), [
            'order_no' => 'required',
            'order_date' => 'required|date',
            'order_type' => 'required|in:purchase,sales,purchaseorder',
            'party_id' => [
                Rule::requiredIf($request->order_type === 'purchase' || $request->order_type === 'sales'),
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->order_type === 'purchase') {
                        if (!DB::table('bk_supplier')->where('id', $value)->exists()) {
                            $fail('The selected party_id is invalid for purchase orders.');
                        }
                    } elseif ($request->order_type === 'sales') {
                        if (!DB::table('party')->where('id', $value)->exists()) {
                            $fail('The selected party_id is invalid for sales orders.');
                        }
                    } elseif ($request->order_type === 'purchaseorder') {
                        if (!DB::table('bk_supplier')->where('id', $value)->exists()) {
                            $fail('The selected party_id is invalid for purchase orders.');
                        }
                    }
                }
            ],
            'company_name_billing'=> 'nullable',
            'company_address_billing'=> 'nullable',
            'company_city_billing'=> 'nullable',
            'company_state_id_billing' => 'required|exists:states,id',
            'company_email_billing' => 'nullable|email',
            'contact_number_billing'=> 'nullable|numeric',
            'company_GST_number_billing'=> 'nullable',
            'company_pincode_billing'=> 'nullable',
            'company_name_shipping'=> 'nullable',
            'company_address_shipping'=> 'nullable',
            'company_city_shipping'=> 'nullable',
            'company_state_id_shipping' => 'nullable|exists:states,id',
            'company_pincode_shipping'=> 'nullable',
            'company_email_shipping'=> 'nullable',
            'company_GST_number_shipping'=> 'nullable',
            'contact_number_shipping'=> 'nullable',
            'order_discount' => 'nullable|numeric|min:0|max:100',
            'line_items' => 'required|array',
            'line_items.*.product_name' => 'required|string',
            'line_items.*.product_description' => 'nullable|string',
            'line_items.*.product_hsn' => 'nullable|string',
            'line_items.*.quantity' => 'required|numeric',
            'line_items.*.rate' => 'required|numeric',
            'line_items.*.gst_rate' => 'nullable|numeric',
            'line_items.*.gst_value' => 'nullable|numeric',
            'line_items.*.unit' => 'nullable|string',
            'line_items.*.amount' => 'nullable|numeric',
            'line_items.*.line_item_discount' => 'nullable|numeric|min:0|max:100',
            ])->after(function ($validator) use ($request) {
                $products = $request->input('line_items', []);
            
                $hasTax = collect($products)->contains(fn($product) => isset($product['gst_rate']));
                $hasNoTax = collect($products)->contains(fn($product) => !isset($product['gst_rate']));
            
                if ($hasTax && $hasNoTax) {
                    $validator->errors()->add('Products', 'Either all products must have gst_rate or none should have it.');
                }
            });

        if ($validator->fails()) {
            return static::errorResponse($validator->errors()->all(), 422);
        }

        DB::beginTransaction();
        try {
            // Determine financial year based on proforma_invoice_date
            $financialYear =  $this->getFinancialYear($request->order_date);
            // Fetch billing state details if available
            $billingState = null;
            if ($request->company_state_id_billing) {
                $billingState = State::find($request->company_state_id_billing);
            }

            // Fetch shipping state details if available
            $shippingState = null;
            if ($request->company_state_id_shipping) {
                $shippingState = State::find($request->company_state_id_shipping);
            }
             
            // Get order-level discount
            $orderDiscount = $request->order_discount ?? 0;
           
            $Data = [
                'order_type' => $request->order_type,
                'tenant_id' => $Id,
                'company_id' => $activeCompanyId,
                'order_no' =>  $request->order_no,
                'order_date' => $request->order_date,
                'financial_year'=> $financialYear,
                'party_id' => $request->party_id ?? null,
                'company_name_billing' => $request->company_name_billing ?? null,
                'company_address_billing' => $request->company_address_billing ?? null,
                'company_city_billing' => $request->company_city_billing ?? null,
                'company_state_id_billing' => $request->company_state_id_billing,
                'company_state_name_billing' =>$billingState->title ?? null,
                'company_email_billing' => $request->company_email_billing ?? null,
                'contact_number_billing' => $request->contact_number_billing ?? null,
                'company_GST_number_billing' => $request->company_GST_number_billing ?? null,
                'company_pincode_billing' => $request->company_pincode_billing ?? null,
                'company_name_shipping' => $request->company_name_shipping ?? null,
                'company_address_shipping' => $request->company_address_shipping ?? null,
                'company_city_shipping' => $request->company_city_shipping ?? null,
                'company_state_id_shipping' => $request->company_state_id_shipping ?? null,
                'company_state_name_shipping' => $shippingState->title ?? null,
                'company_pincode_shipping' => $request->company_pincode_shipping ?? null,
                'company_email_shipping' => $request->company_email_shipping ?? null,
                'company_GST_number_shipping' => $request->company_GST_number_shipping ?? null,
                'contact_number_shipping' => $request->contact_number_shipping ?? null,
                'payment_terms' => $request->payment_terms ?? null,
                'notes' => $request->notes ?? null,
                'terms_and_conditions' => $request->terms_and_conditions ?? null,
                'order_discount' => $orderDiscount,
            ];

            $bkorder = bkorder::create($Data);

            $totalAmount = 0;
            $totalBeforeOrderDiscount = 0;

            foreach ($request->line_items as $item) {
                // Calculate GST value
                // Calculate base amount
                $baseAmount = $item['rate'] * $item['quantity'];
                
                // Apply line item discount if provided
                $lineItemDiscount = $item['line_item_discount'] ?? 0;
                $lineItemDiscountAmount = ($baseAmount * $lineItemDiscount) / 100;
                $amountAfterLineDiscount = $baseAmount - $lineItemDiscountAmount;
                
                // Calculate GST on discounted amount
                $gstRate = $item['gst_rate'] ?? 0;
                $gstValue = ($amountAfterLineDiscount * $gstRate) / 100;
                
                // Calculate total amount including GST
                $calculatedAmount = $amountAfterLineDiscount + $gstValue;
                
                // Add to total before order discount
                $totalBeforeOrderDiscount += $calculatedAmount;
                bkorderlineitems::create([
                    'order_id' => $bkorder->id,
                    'product_name' => $item['product_name'],
                    'product_description' => $item['product_description'] ?? null,
                    'product_hsn' => $item['product_hsn'] ?? null,
                    'quantity' => $item['quantity'],
                    'rate' => $item['rate'],
                    'gst_rate' => $gstRate,
                    'gst_value' => $gstValue,
                    'unit' => $item['unit'] ?? null,
                    'amount' => $baseAmount,
                    'line_item_discount' => $lineItemDiscount,
                    'line_item_discount_amount' => $lineItemDiscountAmount,
                    'amount_after_line_discount' => $amountAfterLineDiscount,
                    'amount_with_gst' => $calculatedAmount,
                ]);
            }

           // Apply order-level discount to the total
            $orderDiscountAmount = ($totalBeforeOrderDiscount * $orderDiscount) / 100;
            $totalAmount = $totalBeforeOrderDiscount - $orderDiscountAmount;
            $totalAmountroundoff = round($totalAmount);

            $bkorder->update([
                'subtotal' => $totalBeforeOrderDiscount,
                'order_discount_amount' => $orderDiscountAmount,
                'order_amount' => $totalAmount,
                'total_amount' => $totalAmountroundoff,
            ]);


           

            DB::commit();
            return $this->show($request,$bkorder->id);
        } catch (\Exception $e) {
            DB::rollBack();
            return static::errorResponse(['Failed to create  order', $e->getMessage()], 500);
        }
    }


   
    public function show(Request $request, $id)
    {
        $authHeader = $request->header('Authorization');
        $showTransactionPermission = $this->checkPermission('Book-Keeping-Purchase-Transactions-Show');
        $showOrderPermission = $this->checkPermission('Book-Keeping-Purchase-Order-Show');
        $showSalesPermission = $this->checkPermission('Book-Keeping-Sales-Transactions-Show');

        // If ANY permission check fails (assuming it returns an error response), return it
        // Only if ALL permissions fail, return the error
        if ($showTransactionPermission && $showOrderPermission && $showSalesPermission) {
            return $showTransactionPermission; // or any of the error responses
        }

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;

        try {
            $Order = bkorder::with('bkorderlineitems')->where('tenant_id', $Id)->where('company_id', $activeCompanyId)->find($id); 

            if(!$Order) {
                return static::errorResponse(['Invalid  Order ID'], 404);
            }

            $company = company::with('state')->where('id', $activeCompanyId)
                            ->where('tenant_id',  $Id)
                            ->first();
            
            if (!$company) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Company not found',
                ], 422);
            }

            $state = State::find($Order->company_state_id_billing);

            // Determine if CGST & SGST or IGST should be applied
            $isLocal = ($state->id === $company->state->id);

            // Get order discount percentage
            $orderDiscount = $Order->order_discount ?? 0;

            // Initialize total GST values
            $totalCgst = 0;
            $totalSgst = 0;
            $totalIgst = 0;

            // Initialize GST summary array
            $gstSummary = [];

            // Initialize discount tracking variables
            $totalLineItemDiscountAmount = 0;
            $totalAmountBeforeLineDiscounts = 0;
            $totalTaxableAmountAfterOrderDiscount = 0;

            foreach ($Order->bkorderlineitems as $item) {
                // Calculate base amount (before any discounts)
                $baseAmount = $item->quantity * $item->rate;
                $totalAmountBeforeLineDiscounts += $baseAmount;
                
                // Get line item discount (if exists in database or default to 0)
                $lineItemDiscount = $item->line_item_discount ?? 0;
                $lineItemDiscountAmount = ($baseAmount * $lineItemDiscount) / 100;
                $totalLineItemDiscountAmount += $lineItemDiscountAmount;
                
                // Calculate amount after line item discount
                $amountAfterLineDiscount = $baseAmount - $lineItemDiscountAmount;
                
                // Apply order discount to the line item amount (proportionally)
                $orderDiscountAmountForItem = ($amountAfterLineDiscount * $orderDiscount) / 100;
                $taxableAmountAfterOrderDiscount = $amountAfterLineDiscount - $orderDiscountAmountForItem;
                $totalTaxableAmountAfterOrderDiscount += $taxableAmountAfterOrderDiscount;
                
                // Use the amount after both discounts as taxable amount for GST calculation
                $taxableAmount = $taxableAmountAfterOrderDiscount;
                $gstRate = $item->gst_rate;
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

                // Add GST rate-wise breakdown (using taxable amount after order discount)
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

                // Calculate total amount including GST for each rate
                $gstSummary[$gstRate]['total_with_gst'] = $gstSummary[$gstRate]['taxable_amount'] + $gstSummary[$gstRate]['total_gst'];
            }

            // Calculate totals (already includes order discount)
            $totalTaxableAmount = $totalTaxableAmountAfterOrderDiscount;
            $totalGstAmount = array_sum(array_column($gstSummary, 'total_gst'));
            $totalWithGst = $totalTaxableAmount + $totalGstAmount;
            
            // Calculate order discount amount for display
            $totalAfterLineDiscounts = $totalAmountBeforeLineDiscounts - $totalLineItemDiscountAmount;
            $totalWithGstBeforeOrderDiscount = $totalAfterLineDiscounts + (($totalAfterLineDiscounts * 18) / 100); // Assuming 18% GST for calculation
            $orderDiscountAmount = ($totalWithGstBeforeOrderDiscount * $orderDiscount) / 100;

            // **Fixed Rounding Logic**
            $roundedTotalWithGst = round($totalWithGst);
            $roundOff = round($roundedTotalWithGst - $totalWithGst, 2);

            if ($roundOff < 0) {
                // If rounding down, make round_off negative
                $roundedTotalWithGst = floor($totalWithGst);
                $roundOff = round($roundedTotalWithGst - $totalWithGst, 2);
            } elseif ($roundOff > 0) {
                // If rounding up, make round_off positive
                $roundedTotalWithGst = ceil($totalWithGst);
                $roundOff = round($roundedTotalWithGst - $totalWithGst, 2);
            }

            // Prepare discount summary
            $discountSummary = [
                'total_before_line_discounts' => number_format($totalAmountBeforeLineDiscounts, 2, '.', ''),
                'total_line_item_discount_amount' => number_format($totalLineItemDiscountAmount, 2, '.', ''),
                'total_after_line_discounts' => number_format($totalAmountBeforeLineDiscounts - $totalLineItemDiscountAmount, 2, '.', ''),
                'order_discount_percentage' => number_format($orderDiscount, 2, '.', ''),
                'order_discount_amount' => number_format($orderDiscountAmount, 2, '.', ''),
                'total_after_all_discounts' => number_format($totalWithGst, 2, '.', ''),
            ];

            // Format values to 2 decimal places
            $orderGst = [
                'total_taxable_amount' => number_format($totalTaxableAmount, 2, '.', ''),
                'total_cgst' => $isLocal ? number_format($totalCgst, 2, '.', '') : null,
                'total_sgst' => $isLocal ? number_format($totalSgst, 2, '.', '') : null,
                'total_igst' => !$isLocal ? number_format($totalIgst, 2, '.', '') : null,
                'total_gst' => number_format($totalGstAmount, 2, '.', ''),
                'subtotal_with_gst' => number_format($totalWithGst, 2, '.', ''),
                'total_with_gst' => number_format($roundedTotalWithGst, 2, '.', ''),
                'round_off' => number_format($roundOff, 2, '.', '')
            ];

            // Format gst_summary values
            foreach ($gstSummary as &$gst) {
                $gst['taxable_amount'] = number_format($gst['taxable_amount'], 2, '.', '');
                $gst['cgst'] = number_format($gst['cgst'], 2, '.', '');
                $gst['sgst'] = number_format($gst['sgst'], 2, '.', '');
                $gst['igst'] = number_format($gst['igst'], 2, '.', '');
                $gst['total_gst'] = number_format($gst['total_gst'], 2, '.', '');
                $gst['total_with_gst'] = number_format($gst['total_with_gst'], 2, '.', '');
            }

            // Hsn wise summary (using amounts after order discount)
            $hsnWiseSummary = [];

            foreach ($Order->bkorderlineitems as $item) {
                $hsn = $item->product_hsn;
                $gstRate = $item->gst_rate;
                
                // Calculate base amount and apply line item discount
                $baseAmount = $item->quantity * $item->rate;
                $lineItemDiscount = $item->line_item_discount ?? 0;
                $lineItemDiscountAmount = ($baseAmount * $lineItemDiscount) / 100;
                $amountAfterLineDiscount = $baseAmount - $lineItemDiscountAmount;
                
                // Apply order discount proportionally
                $orderDiscountAmountForItem = ($amountAfterLineDiscount * $orderDiscount) / 100;
                $taxableAmount = $amountAfterLineDiscount - $orderDiscountAmountForItem;
                
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

                // Format rates to 2 decimal places as well
                $hsn['cgst_rate'] = number_format($hsn['cgst_rate'], 2, '.', '');
                $hsn['sgst_rate'] = number_format($hsn['sgst_rate'], 2, '.', '');
                $hsn['igst_rate'] = number_format($hsn['igst_rate'], 2, '.', '');
            }

            return static::successResponse([
                'Order' => $Order,
                'Order_gst' => array_filter($orderGst), // Remove null values
                'discount_summary' => $discountSummary,
                'gst_summary' => $gstSummary,
                'hsnwisesummary' => $hsnWiseSummary,
                'company' => [
                    'id' => $company->id,
                    'name' => $company->company_name,
                    'address_line_1' => $company->address1,
                    'address_line_2' => $company->address2,
                    'city' => $company->city,
                    'state' => $company->state->title,
                    'pincode' => $company->pincode,
                    'gst_number' => $company->gst_number,
                ],
            ], 'Order details Fetched');
        } catch (\Exception $e) {
            return static::errorResponse(['Failed to retrieve  Order', $e->getMessage()], 500);
        }
    }

    
    
    public function update(Request $request, $id)
    {
        $authHeader = $request->header('Authorization');
        $updateTransactionPermission = $this->checkPermission('Book-Keeping-Purchase-Transactions-Update');
        $updateOrderPermission = $this->checkPermission('Book-Keeping-Purchase-Order-Update');
        $updateSalesPermission = $this->checkPermission('Book-Keeping-Sales-Transactions-Update');

        // If ANY permission check fails (assuming it returns an error response), return it
        // Only if ALL permissions fail, return the error
        if ($updateTransactionPermission && $updateOrderPermission && $updateSalesPermission) {
            return $updateTransactionPermission; // or any of the error responses
        }

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;

        $validator = Validator::make($request->all(), [
            'order_no' => 'required',
            'order_date' => 'required|date',
            'order_type' => 'required|in:purchase,sales,purchaseorder',
            'party_id' => [
                Rule::requiredIf($request->order_type === 'purchase' || $request->order_type === 'sales' || $request->order_type === 'purchaseorder'),
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->order_type === 'purchase') {
                        if (!DB::table('bk_supplier')->where('id', $value)->exists()) {
                            $fail('The selected party_id is invalid for purchase orders.');
                        }
                    } elseif ($request->order_type === 'sales') {
                        if (!DB::table('party')->where('id', $value)->exists()) {
                            $fail('The selected party_id is invalid for sales orders.');
                        }
                    } elseif ($request->order_type === 'purchaseorder') {
                        if (!DB::table('bk_supplier')->where('id', $value)->exists()) {
                            $fail('The selected party_id is invalid for purchase orders.');
                        }
                    }
                }
            ],
            'company_name_billing'=> 'nullable',
            'company_address_billing'=> 'nullable',
            'company_city_billing'=> 'nullable',
            'company_state_id_billing' => 'required|exists:states,id',
            'company_email_billing' => 'nullable|email',
            'contact_number_billing'=> 'nullable|numeric',
            'company_GST_number_billing'=> 'nullable',
            'company_pincode_billing'=> 'nullable',
            'company_name_shipping'=> 'nullable',
            'company_address_shipping'=> 'nullable',
            'company_city_shipping'=> 'nullable',
            'company_state_id_shipping' => 'nullable|exists:states,id',
            'company_pincode_shipping'=> 'nullable',
            'company_email_shipping'=> 'nullable',
            'company_GST_number_shipping'=> 'nullable',
            'contact_number_shipping'=> 'nullable',
            'order_discount' => 'nullable|numeric|min:0|max:100',
            'line_items' => 'required|array',
            'line_items.*.product_name' => 'required|string',
            'line_items.*.product_description' => 'nullable|string',
            'line_items.*.product_hsn' => 'nullable|string',
            'line_items.*.quantity' => 'required|numeric',
            'line_items.*.rate' => 'required|numeric',
            'line_items.*.gst_rate' => 'nullable|numeric',
            'line_items.*.gst_value' => 'nullable|numeric',
            'line_items.*.unit' => 'nullable|string',
            'line_items.*.amount' => 'nullable|numeric',
            'line_items.*.line_item_discount' => 'nullable|numeric|min:0|max:100',
            ])->after(function ($validator) use ($request) {
                $products = $request->input('line_items', []);
            
                $hasTax = collect($products)->contains(fn($product) => isset($product['gst_rate']));
                $hasNoTax = collect($products)->contains(fn($product) => !isset($product['gst_rate']));
            
                if ($hasTax && $hasNoTax) {
                    $validator->errors()->add('Products', 'Either all products must have gst_rate or none should have it.');
                }
            });

        if ($validator->fails()) {
            return static::errorResponse($validator->errors()->all(), 422);
        }
    
        DB::beginTransaction(); // Start transaction
    
        try {
            $Order = bkorder::with('bkorderlineitems')->where('tenant_id', $Id)->where('company_id', $activeCompanyId)->find($id); 

            if(!$Order) {
                return static::errorResponse(['Invalid  Order ID'], 404);
            }

            if (!$Order->can_update) {
                return static::errorResponse(['Failed to update order,This order cannot be updated because some items have been returned.'], 422);
            }

            $fieldsToUpdate = [];

            // Get order-level discount
            $orderDiscount = $request->order_discount ?? 0;
            
            if ($request->filled('order_no') || $request->order_no === null || $request->order_no === '') {
                $fieldsToUpdate['order_no'] = $request->order_no;
            }
    
            if ($request->filled('order_date') || $request->order_date === null || $request->order_date === '') {
                $fieldsToUpdate['order_date'] = $request->order_date;
                $financialYear = $this->getFinancialYear($request->order_date);
                $fieldsToUpdate['financial_year'] =  $financialYear;
            }

            if ($request->filled('party_id')  || $request->party_id === null || $request->party_id === '') {
                $fieldsToUpdate['party_id'] = $request->party_id;
            }
    
          
            if ($request->filled('company_name_billing')  || $request->company_name_billing === null || $request->company_name_billing === '') {
                $fieldsToUpdate['company_name_billing'] = $request->company_name_billing;
            }
    
            if ($request->filled('company_address_billing')  || $request->company_address_billing === null || $request->company_address_billing === '') {
                $fieldsToUpdate['company_address_billing'] = $request->company_address_billing;
            }

            if ($request->filled('company_city_billing')  || $request->company_city_billing === null || $request->company_city_billing === '') {
                $fieldsToUpdate['company_city_billing'] = $request->company_city_billing;
            }

            if ($request->filled('company_pincode_billing')  || $request->company_pincode_billing === null || $request->company_pincode_billing === '') {
                $fieldsToUpdate['company_pincode_billing'] = $request->company_pincode_billing;
            }
    
            if ($request->filled('company_state_id_billing')  || $request->company_state_id_billing === null || $request->company_state_id_billing === '') {
                $fieldsToUpdate['company_state_id_billing'] = $request->company_state_id_billing;
                $billingState = State::find($request->company_state_id_billing);
                $fieldsToUpdate['company_state_name_billing'] = $billingState->title ?? null;

            }

            if ($request->filled('company_email_billing')  || $request->company_email_billing === null || $request->company_email_billing === '') {
                $fieldsToUpdate['company_email_billing'] = $request->company_email_billing;
            }
    
            if ($request->filled('company_GST_number_billing')  || $request->company_GST_number_billing === null || $request->company_GST_number_billing === '') {
                $fieldsToUpdate['company_GST_number_billing'] = $request->company_GST_number_billing;
            }
    
            if ($request->filled('contact_number_billing')  || $request->contact_number_billing === null || $request->contact_number_billing === '') {
                $fieldsToUpdate['contact_number_billing'] = $request->contact_number_billing;
            }

             if ($request->filled('company_name_shipping') || $request->company_name_shipping === null || $request->company_name_shipping === '') {
                $fieldsToUpdate['company_name_shipping'] = $request->company_name_shipping;
            }

            if ($request->filled('company_address_shipping')  || $request->company_address_shipping === null || $request->company_address_shipping === '') {
                $fieldsToUpdate['company_address_shipping'] = $request->company_address_shipping;
            }

            if ($request->filled('company_city_shipping')  || $request->company_city_shipping === null || $request->company_city_shipping === '') {
                $fieldsToUpdate['company_city_shipping'] = $request->company_city_shipping;
            }

            if ($request->filled('company_pincode_shipping')  || $request->company_pincode_shipping === null || $request->company_pincode_shipping === '') {
                $fieldsToUpdate['company_pincode_shipping'] = $request->company_pincode_shipping;
            }

            if ($request->filled('company_state_id_shipping')  || $request->company_state_id_shipping === null || $request->company_state_id_shipping === '') {
                $shippingState = State::find($request->company_state_id_shipping);
                $fieldsToUpdate['company_state_id_shipping'] = $request->company_state_id_shipping;
                $fieldsToUpdate['company_state_name_shipping'] = $shippingState->title ?? null;
            
            }


            if ($request->filled('company_email_shipping')  || $request->company_email_shipping === null || $request->company_email_shipping === '') {
                $fieldsToUpdate['company_email_shipping'] = $request->company_email_shipping;
            }

            if ($request->filled('company_GST_number_shipping')  || $request->company_GST_number_shipping === null || $request->company_GST_number_shipping === '') {
                $fieldsToUpdate['company_GST_number_shipping'] = $request->company_GST_number_shipping;
            }

            if ($request->filled('contact_number_shipping')  || $request->contact_number_shipping === null || $request->contact_number_shipping === '') {
                $fieldsToUpdate['contact_number_shipping'] = $request->contact_number_shipping;
            }

            
            if ($request->filled('payment_terms')  || $request->payment_terms === null || $request->payment_terms === '') {
                $fieldsToUpdate['payment_terms'] = $request->payment_terms;
            }
    
            if ($request->filled('notes')  || $request->notes === null || $request->notes === '') {
                $fieldsToUpdate['notes'] = $request->notes;
            }

            if ($request->filled('terms_and_conditions')  || $request->terms_and_conditions === null || $request->terms_and_conditions === '') {
                $fieldsToUpdate['terms_and_conditions'] = $request->terms_and_conditions;
            }

            if ($request->filled('order_discount')  || $request->order_discount === null || $request->order_discount === '') {
                $fieldsToUpdate['order_discount'] = $request->order_discount;
            }
    
            // Update order with provided fields
            $Order->update($fieldsToUpdate);
    
            // Delete existing line items
            $Order->bkorderlineitems()->delete();
    
            $totalAmount = 0;
            $totalBeforeOrderDiscount = 0;

            // Create new line items
            foreach ($request->line_items as $item) {
                // Calculate GST value
                // Calculate base amount
                $baseAmount = $item['rate'] * $item['quantity'];
                
                // Apply line item discount if provided
                $lineItemDiscount = $item['line_item_discount'] ?? 0;
                $lineItemDiscountAmount = ($baseAmount * $lineItemDiscount) / 100;
                $amountAfterLineDiscount = $baseAmount - $lineItemDiscountAmount;
                
                // Calculate GST on discounted amount
                $gstRate = $item['gst_rate'] ?? 0;
                $gstValue = ($amountAfterLineDiscount * $gstRate) / 100;
                
                // Calculate total amount including GST
                $calculatedAmount = $amountAfterLineDiscount + $gstValue;
                
                // Add to total before order discount
                $totalBeforeOrderDiscount += $calculatedAmount;
                bkorderlineitems::create([
                    'order_id' => $Order->id,
                    'product_name' => $item['product_name'],
                    'product_description' => $item['product_description'] ?? null,
                    'product_hsn' => $item['product_hsn'] ?? null,
                    'quantity' => $item['quantity'],
                    'rate' => $item['rate'],
                    'gst_rate' => $gstRate,
                    'gst_value' => $gstValue,
                    'unit' => $item['unit'] ?? null,
                    'amount' => $baseAmount,
                    'line_item_discount' => $lineItemDiscount,
                    'line_item_discount_amount' => $lineItemDiscountAmount,
                    'amount_after_line_discount' => $amountAfterLineDiscount,
                    'amount_with_gst' => $calculatedAmount,
                ]);
            }

           // Apply order-level discount to the total
            $orderDiscountAmount = ($totalBeforeOrderDiscount * $orderDiscount) / 100;
            $totalAmount = $totalBeforeOrderDiscount - $orderDiscountAmount;
            $totalAmountroundoff = round($totalAmount);
            
            $Order->update([
                'subtotal' => $totalBeforeOrderDiscount,
                'order_discount_amount' => $orderDiscountAmount,
                'order_amount' => $totalAmount,
                'total_amount' => $totalAmountroundoff,
            ]);

    
            DB::commit(); // Commit transaction if everything is successful

           
            return $this->show($request, $id);
    
        } catch (\Exception $e) {
            DB::rollBack(); // Rollback transaction on failure
            return static::errorResponse(['Failed to update order', $e->getMessage()], 500);
        }
    }

    
    public function destroy(Request $request, $id)
    {
        $authHeader = $request->header('Authorization');
        $deleteTransactionPermission = $this->checkPermission('Book-Keeping-Purchase-Transactions-Delete');
        $deleteOrderPermission = $this->checkPermission('Book-Keeping-Purchase-Order-Delete');
        $deleteSalesPermission = $this->checkPermission('Book-Keeping-Sales-Transactions-Delete');

        // If ANY permission check fails (assuming it returns an error response), return it
        // Only if ALL permissions fail, return the error
        if ($deleteTransactionPermission && $deleteOrderPermission && $deleteSalesPermission) {
            return $deleteTransactionPermission; // or any of the error responses
        }
        
        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;


        try {
           $Order = bkorder::with('bkorderlineitems')->where('tenant_id', $Id)->where('company_id', $activeCompanyId)->find($id); 

            if(!$Order) {
                return static::errorResponse(['Invalid  Order ID'], 404);
            }
        
            $Order->bkorderlineitems()->delete();
            $Order->delete();

        

            return static::successResponse(null, 'Order deleted successfully');
        } catch (\Exception $e) {
            if ($e instanceof \Illuminate\Database\QueryException) {
                // Check for foreign key constraint error codes
                if ($e->getCode() == 23000 || strpos($e->getMessage(), 'Integrity constraint violation') !== false || strpos($e->getMessage(), 'foreign key constraint fails') !== false) {
                    return response()->json([
                        'status' => 409,
                        'message' => 'Cannot delete this record because there are linked records associated with it. Please remove all related data first.',
                    ], 200);
                }
            }
            return static::errorResponse(['Failed to delete  Order', $e->getMessage()], 500);
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

    public function generatePdf($id, Request $request)
    {
        $authHeader = $request->header('Authorization');
        $showTransactionPermission = $this->checkPermission('Book-Keeping-Purchase-Transactions-Show');
        $showOrderPermission = $this->checkPermission('Book-Keeping-Purchase-Order-Show');
        $showSalesPermission = $this->checkPermission('Book-Keeping-Sales-Transactions-Show');

        // If ANY permission check fails (assuming it returns an error response), return it
        // Only if ALL permissions fail, return the error
        if ($showTransactionPermission && $showOrderPermission && $showSalesPermission) {
            return $showTransactionPermission; // or any of the error responses
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        $userSetting = usersettings::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->get();
            
        // Convert user settings to a key-value array for easier access
        $userSettings = [];
        foreach ($userSetting as $setting) {
            $userSettings[$setting->slug] = $setting->val;
        }

        try {
            // Get order details with condition for sales orders only
            $order = bkorder::with('bkorderlineitems', 'company.state')
                ->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->where('id', $id)
                ->first();

            if (!$order) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Order not found',
                ], 404);
            }

            // Get company details
            $company = company::with('state')->where('id', $activeCompanyId)
                            ->where('tenant_id', $tenantId)
                            ->first();
            
            if (!$company) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Company not found',
                ], 422);
            }

            $state = State::find($order->company_state_id_billing);

            // Determine if CGST & SGST or IGST should be applied
            $isLocal = ($state->id === $company->state->id);

            // Get order discount percentage
            $orderDiscount = $order->order_discount ?? 0;

            // Check if tax should be included based on user settings and order type
            $includeTax = false;
            if ($order->order_type == 'sales' && isset($userSettings['sales_include_tax']) && $userSettings['sales_include_tax'] == 'yes') {
                $includeTax = true;
            } elseif ($order->order_type == 'purchase' && isset($userSettings['purchase_include_tax']) && $userSettings['purchase_include_tax'] == 'yes') {
                $includeTax = true;
            } elseif ($order->order_type == 'purchaseorder' && isset($userSettings['purchase_order_include_tax']) && $userSettings['purchase_order_include_tax'] == 'yes') {
                $includeTax = true;
            }

            // Check if bank details should be included
            $includeBankDetails = false;
            if ($order->order_type == 'sales' && isset($userSettings['sales_include_bank_details']) && $userSettings['sales_include_bank_details'] == 'yes') {
                $includeBankDetails = true;
            } elseif ($order->order_type == 'purchase' && isset($userSettings['purchase_include_bank_details']) && $userSettings['purchase_include_bank_details'] == 'yes') {
                $includeBankDetails = true;
            } elseif ($order->order_type == 'purchaseorder' && isset($userSettings['purchase_order_include_bank_details']) && $userSettings['purchase_order_include_bank_details'] == 'yes') {
                $includeBankDetails = true;
            }

            // Initialize total GST values
            $totalCgst = 0;
            $totalSgst = 0;
            $totalIgst = 0;

            // Initialize GST summary array
            $gstSummary = [];

            // Initialize discount tracking variables
            $totalLineItemDiscountAmount = 0;
            $totalAmountBeforeLineDiscounts = 0;
            $totalTaxableAmountAfterOrderDiscount = 0;

            foreach ($order->bkorderlineitems as $item) {
                // Calculate base amount (before any discounts)
                $baseAmount = $item->quantity * $item->rate;
                $totalAmountBeforeLineDiscounts += $baseAmount;
                
                // Get line item discount (if exists in database or default to 0)
                $lineItemDiscount = $item->line_item_discount ?? 0;
                $lineItemDiscountAmount = ($baseAmount * $lineItemDiscount) / 100;
                $totalLineItemDiscountAmount += $lineItemDiscountAmount;
                
                // Calculate amount after line item discount
                $amountAfterLineDiscount = $baseAmount - $lineItemDiscountAmount;
                
                // Apply order discount to the line item amount (proportionally)
                $orderDiscountAmountForItem = ($amountAfterLineDiscount * $orderDiscount) / 100;
                $taxableAmountAfterOrderDiscount = $amountAfterLineDiscount - $orderDiscountAmountForItem;
                $totalTaxableAmountAfterOrderDiscount += $taxableAmountAfterOrderDiscount;
                
                // Use the amount after both discounts as taxable amount for GST calculation
                $taxableAmount = $taxableAmountAfterOrderDiscount;
                $gstRate = $item->gst_rate;
                
                // Only calculate GST if tax should be included
                $gstAmount = $includeTax ? ($taxableAmount * $gstRate) / 100 : 0;

                if ($includeTax) {
                    if ($isLocal) {
                        $cgst = $gstAmount / 2;
                        $sgst = $gstAmount / 2;
                        $totalCgst += $cgst;
                        $totalSgst += $sgst;
                    } else {
                        $igst = $gstAmount;
                        $totalIgst += $igst;
                    }

                    // Add GST rate-wise breakdown (using taxable amount after order discount)
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

                    // Calculate total amount including GST for each rate
                    $gstSummary[$gstRate]['total_with_gst'] = $gstSummary[$gstRate]['taxable_amount'] + $gstSummary[$gstRate]['total_gst'];
                }
            }

            // Calculate totals (already includes order discount)
            $totalTaxableAmount = $totalTaxableAmountAfterOrderDiscount;
            $totalGstAmount = $includeTax ? array_sum(array_column($gstSummary, 'total_gst')) : 0;
            $totalWithGst = $totalTaxableAmount + $totalGstAmount;
            
            // Calculate order discount amount for display
            $totalAfterLineDiscounts = $totalAmountBeforeLineDiscounts - $totalLineItemDiscountAmount;
            $totalWithGstBeforeOrderDiscount = $totalAfterLineDiscounts + ($includeTax ? (($totalAfterLineDiscounts * 18) / 100) : 0); // Assuming 18% GST for calculation
            $orderDiscountAmount = ($totalWithGstBeforeOrderDiscount * $orderDiscount) / 100;

            // **Fixed Rounding Logic**
            $roundedTotalWithGst = round($totalWithGst);
            $roundOff = round($roundedTotalWithGst - $totalWithGst, 2);

            if ($roundOff < 0) {
                // If rounding down, make round_off negative
                $roundedTotalWithGst = floor($totalWithGst);
                $roundOff = round($roundedTotalWithGst - $totalWithGst, 2);
            } elseif ($roundOff > 0) {
                // If rounding up, make round_off positive
                $roundedTotalWithGst = ceil($totalWithGst);
                $roundOff = round($roundedTotalWithGst - $totalWithGst, 2);
            }

            // Prepare discount summary
            $discountSummary = [
                'total_before_line_discounts' => number_format($totalAmountBeforeLineDiscounts, 2, '.', ''),
                'total_line_item_discount_amount' => number_format($totalLineItemDiscountAmount, 2, '.', ''),
                'total_after_line_discounts' => number_format($totalAmountBeforeLineDiscounts - $totalLineItemDiscountAmount, 2, '.', ''),
                'order_discount_percentage' => number_format($orderDiscount, 2, '.', ''),
                'order_discount_amount' => number_format($orderDiscountAmount, 2, '.', ''),
                'total_after_all_discounts' => number_format($totalWithGst, 2, '.', ''),
            ];

            // Format values to 2 decimal places
            $orderGst = [
                'total_taxable_amount' => number_format($totalTaxableAmount, 2, '.', ''),
                'total_cgst' => ($includeTax && $isLocal) ? number_format($totalCgst, 2, '.', '') : null,
                'total_sgst' => ($includeTax && $isLocal) ? number_format($totalSgst, 2, '.', '') : null,
                'total_igst' => ($includeTax && !$isLocal) ? number_format($totalIgst, 2, '.', '') : null,
                'total_gst' => $includeTax ? number_format($totalGstAmount, 2, '.', '') : '0.00',
                'subtotal_with_gst' => number_format($totalWithGst, 2, '.', ''),
                'total_with_gst' => number_format($roundedTotalWithGst, 2, '.', ''),
                'round_off' => number_format($roundOff, 2, '.', '')
            ];

            // Format gst_summary values only if tax is included
            if ($includeTax) {
                foreach ($gstSummary as &$gst) {
                    $gst['taxable_amount'] = number_format($gst['taxable_amount'], 2, '.', '');
                    $gst['cgst'] = number_format($gst['cgst'], 2, '.', '');
                    $gst['sgst'] = number_format($gst['sgst'], 2, '.', '');
                    $gst['igst'] = number_format($gst['igst'], 2, '.', '');
                    $gst['total_gst'] = number_format($gst['total_gst'], 2, '.', '');
                    $gst['total_with_gst'] = number_format($gst['total_with_gst'], 2, '.', '');
                }
            }

            // HSN wise summary (using amounts after order discount) - only if tax is included
            $hsnWiseSummary = [];
            if ($includeTax) {
                foreach ($order->bkorderlineitems as $item) {
                    $hsn = $item->product_hsn;
                    $gstRate = $item->gst_rate;
                    
                    // Calculate base amount and apply line item discount
                    $baseAmount = $item->quantity * $item->rate;
                    $lineItemDiscount = $item->line_item_discount ?? 0;
                    $lineItemDiscountAmount = ($baseAmount * $lineItemDiscount) / 100;
                    $amountAfterLineDiscount = $baseAmount - $lineItemDiscountAmount;
                    
                    // Apply order discount proportionally
                    $orderDiscountAmountForItem = ($amountAfterLineDiscount * $orderDiscount) / 100;
                    $taxableAmount = $amountAfterLineDiscount - $orderDiscountAmountForItem;
                    
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

                    // Format rates to 2 decimal places as well
                    $hsn['cgst_rate'] = number_format($hsn['cgst_rate'], 2, '.', '');
                    $hsn['sgst_rate'] = number_format($hsn['sgst_rate'], 2, '.', '');
                    $hsn['igst_rate'] = number_format($hsn['igst_rate'], 2, '.', '');
                }
            }

            // Get bank details from user settings
            $bankDetails = [
                'bank_name' => $userSettings['bank_name'] ?? '',
                'account_no' => $userSettings['bank_account_number'] ?? '',
                'ifsc_code' => $userSettings['bank_ifdc_code'] ?? '',
                'branch' => $userSettings['bank_branch'] ?? ''
            ];

            // Initialize DomPDF options
            $options = new Options();
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isRemoteEnabled', true);

            // Get template number from request parameter, default to 1
            $templateNumber = $request->get('template', 1);

            if($order->order_type=='sales')
            {
            // Determine template view based on template number
            $templateView = ($templateNumber == 2) ? 'pdf.salestemplate2' : 'pdf.salestemplate1';
            }
            elseif($order->order_type=='purchase')
            {
            $templateView = ($templateNumber == 2) ? 'pdf.purchasetemplate2' : 'pdf.purchasetemplate1';  
            }
            elseif($order->order_type=='purchaseorder')
            {
            $templateView = ($templateNumber == 2) ? 'pdf.purchaseordertemplate2' : 'pdf.purchaseordertemplate1';  
            }
            else
            {
                return response()->json([
                    'status' => 404,
                    'message' => 'Failed to generate PDF: Undefined Order Type',
                ], 404);
            }
                    
            // Prepare the view with required data
            $view = view($templateView, [
                'order' => $order,
                'userSetting' => $userSetting,
                'userSettings' => $userSettings,
                'orderGst' => $orderGst,
                'discountSummary' => $discountSummary,
                'gstSummary' => $gstSummary,
                'hsnWiseSummary' => $hsnWiseSummary,
                'includeTax' => $includeTax,
                'includeBankDetails' => $includeBankDetails,
                'bankDetails' => $bankDetails,
                'isLocal'=>$isLocal
            ])->render();

            // Initialize Dompdf
            $pdf = new Dompdf($options);

            // Load HTML content
            $pdf->loadHtml($view, 'UTF-8');

            // Set paper size and orientation
            $pdf->setPaper('A4', 'portrait'); // Changed to A4 for orders

            // Render the PDF
            $pdf->render();

            // Output PDF content
            $output = $pdf->output();

            // Return response with correct headers
            return response($output, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="order_' . $order->id . '.pdf"');

        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to generate Order PDF: ' . $e->getMessage(),
            ], 500);
        }
    }


}

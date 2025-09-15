<?php

namespace Modules\Masters\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Models\quotation;
use App\Models\quotationlineitems;
use App\Models\State;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Http;
use App\Models\company;
use App\Models\usersettings;
use Dompdf\Dompdf;
use Dompdf\Options;

class QuotationController extends ApiController
{
    public function index(Request $request)
    {

        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Quotation-Generator-Show');
        if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;

        try {
            $perPage = $request->input('per_page', 10);
            $page = $request->input('page', 1);
            $query = quotation::with('quotationlineitems')->where('tenant_id', $Id)->where('company_id', $activeCompanyId);
            $search = $request->input('search');

             // Get the authenticated user
             $user = auth()->user();

          
            if ($request->filled('date_from') && $request->filled('date_to')) {
                $query->whereBetween('quotation_date', [
                    $request->input('date_from'),
                    $request->input('date_to')
                ]);
            }

          
            

            if ($search !== null && $search !== '')
            {
                
                $query->where('quotation_no', 'like', "%{$search}%")
                ->orWhere('company_name_billing', 'like', "%{$search}%")
                ->orWhere('company_name_shipping', 'like', "%{$search}%");
            }

            $query->orderBy('id', 'desc');
            
           

            $quotation = $query->paginate((int)$perPage, ['*'], 'page', (int)$page);
            return $this->paginatedResponse($quotation, 'Quotation retrieved successfully');
        } catch (\Exception $e) {
            return static::errorResponse(['Failed to retrieve Quotation', $e->getMessage()], 500);
        }
    }

    /**
     * Store a newly created purchase order.
     */
    public function store(Request $request)
    {
        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Quotation-Generator-Insert');
        if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;

        $validator = Validator::make($request->all(), [
            'quotation_no' => 'required',
            'quotation_date' => 'required|date',
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
            $financialYear =  $this->getFinancialYear($request->quotation_date);
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
                'tenant_id' => $Id,
                'company_id' => $activeCompanyId,
                'quotation_no' =>  $request->quotation_no,
                'quotation_date' => $request->quotation_date,
                'financial_year'=> $financialYear,
                'client_name' =>  $request->client_name,
                'client_address' =>  $request->client_address,
                'contact_number' =>  $request->contact_number,
                'email' =>  $request->email,
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

            $quotation = quotation::create($Data);
            
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
            
                quotationlineitems::create([
                    'quotation_id' => $quotation->id,
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

            $quotation->update([
                'subtotal' => $totalBeforeOrderDiscount,
                'order_discount_amount' => $orderDiscountAmount,
                'total_amount' => $totalAmount,
            ]);

           

            DB::commit();
            return $this->show($request,$quotation->id);
        } catch (\Exception $e) {
            DB::rollBack();
            return static::errorResponse(['Failed to create quotation', $e->getMessage()], 500);
        }
    }


    /**
     * Display the specified purchase order with GST calculations.
     */
    public function show(Request $request, $id)
    {
        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Quotation-Generator-Show');
        if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;

        try {
            $quotation = quotation::with('quotationlineitems')
                ->where('tenant_id', $Id)
                ->where('company_id', $activeCompanyId)
                ->find($id);

            if (!$quotation) {
                return static::errorResponse(['Invalid quotation ID'], 404);
            }

            $company = company::with('state')
                ->where('id', $activeCompanyId)
                ->where('tenant_id',  $Id)
                ->first();

            if (!$company) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Company not found',
                ], 422);
            }

            $state = State::find($quotation->company_state_id_billing);
            $isLocal = ($state && $company->id) ? ($state->title === $company->state->id) : true;

            // 1) First pass: compute taxable after line-item discount for each item
            $itemsAdjusted = [];
            $totalTaxableBeforeOrderDiscount = 0.0;

            foreach ($quotation->quotationlineitems as $item) {
                $gstRate = (float) $item->gst_rate;
                $hsn = $item->product_hsn;
                $taxable = (float) $item->quantity * (float) $item->rate;

                $lineItemDiscount = (float) ($item->line_item_discount ?? 0);
                $lineItemDiscountAmount = ($taxable * $lineItemDiscount) / 100.0;
                $taxableAfterLine = $taxable - $lineItemDiscountAmount;

                // store per-item taxable (after line-item discount) to distribute order discount later
                $itemsAdjusted[] = [
                    'gst_rate' => $gstRate,
                    'hsn' => $hsn,
                    'taxable_before_order' => $taxableAfterLine,
                ];

                $totalTaxableBeforeOrderDiscount += $taxableAfterLine;
            }

            // 2) Compute order-level discount total (applied on taxable sum)
            $orderDiscountPercent = (float) ($quotation->order_discount ?? 0);
            $orderDiscountTotal = 0.0;
            if ($orderDiscountPercent > 0 && $totalTaxableBeforeOrderDiscount > 0) {
                $orderDiscountTotal = ($totalTaxableBeforeOrderDiscount * $orderDiscountPercent) / 100.0;
            }

            // 3) Second pass: apply proportional order discount per item, then compute GST & aggregates
            $gstSummary = [];
            $hsnWiseSummary = [];
            $totalCgst = 0.0;
            $totalSgst = 0.0;
            $totalIgst = 0.0;

            foreach ($itemsAdjusted as $ia) {
                $rate = (float) $ia['gst_rate'];
                $hsn = $ia['hsn'];
                $taxableBefore = (float) $ia['taxable_before_order'];

                // proportional share of order discount
                $share = ($totalTaxableBeforeOrderDiscount > 0) ? ($taxableBefore / $totalTaxableBeforeOrderDiscount) : 0.0;
                $itemOrderDiscount = $orderDiscountTotal * $share;

                // taxable after distributing order discount
                $taxableAfterOrder = $taxableBefore - $itemOrderDiscount;
                if ($taxableAfterOrder < 0) $taxableAfterOrder = 0.0; // guard

                // GST on discounted taxable
                $gstAmount = ($taxableAfterOrder * $rate) / 100.0;

                $cgst = 0.0; $sgst = 0.0; $igst = 0.0;
                if ($isLocal) {
                    $cgst = $gstAmount / 2.0;
                    $sgst = $gstAmount / 2.0;
                    $totalCgst += $cgst;
                    $totalSgst += $sgst;
                } else {
                    $igst = $gstAmount;
                    $totalIgst += $igst;
                }

                // gstSummary keyed by rate as string (to preserve "18" key style)
                $rateKey = (string) (int) $rate;
                if (!isset($gstSummary[$rateKey])) {
                    $gstSummary[$rateKey] = [
                        'taxable_amount' => 0.0,
                        'cgst' => 0.0,
                        'sgst' => 0.0,
                        'igst' => 0.0,
                        'total_gst' => 0.0,
                        'total_with_gst' => 0.0
                    ];
                }

                $gstSummary[$rateKey]['taxable_amount'] += $taxableAfterOrder;
                if ($isLocal) {
                    $gstSummary[$rateKey]['cgst'] += $cgst;
                    $gstSummary[$rateKey]['sgst'] += $sgst;
                    $gstSummary[$rateKey]['total_gst'] += ($cgst + $sgst);
                } else {
                    $gstSummary[$rateKey]['igst'] += $igst;
                    $gstSummary[$rateKey]['total_gst'] += $igst;
                }
                $gstSummary[$rateKey]['total_with_gst'] = $gstSummary[$rateKey]['taxable_amount'] + $gstSummary[$rateKey]['total_gst'];

                // HSN-wise aggregation (use same per-item gst split)
                $hsnKey = (string) $hsn;
                if (!isset($hsnWiseSummary[$hsnKey])) {
                    $hsnWiseSummary[$hsnKey] = [
                        'taxable_amount' => 0.0,
                        'cgst' => 0.0,
                        'sgst' => 0.0,
                        'igst' => 0.0,
                        'total_gst' => 0.0,
                        'total_with_gst' => 0.0,
                        'cgst_rate' => 0.0,
                        'sgst_rate' => 0.0,
                        'igst_rate' => 0.0
                    ];
                }

                $hsnWiseSummary[$hsnKey]['taxable_amount'] += $taxableAfterOrder;
                if ($isLocal) {
                    $hsnWiseSummary[$hsnKey]['cgst'] += $cgst;
                    $hsnWiseSummary[$hsnKey]['sgst'] += $sgst;
                    $hsnWiseSummary[$hsnKey]['total_gst'] += ($cgst + $sgst);

                    // set rate fields (if multiple rates for same HSN exist, last one wins — this matches previous pattern)
                    $hsnWiseSummary[$hsnKey]['cgst_rate'] = $rate / 2.0;
                    $hsnWiseSummary[$hsnKey]['sgst_rate'] = $rate / 2.0;
                    $hsnWiseSummary[$hsnKey]['igst_rate'] = 0.0;
                } else {
                    $hsnWiseSummary[$hsnKey]['igst'] += $igst;
                    $hsnWiseSummary[$hsnKey]['total_gst'] += $igst;

                    $hsnWiseSummary[$hsnKey]['cgst_rate'] = 0.0;
                    $hsnWiseSummary[$hsnKey]['sgst_rate'] = 0.0;
                    $hsnWiseSummary[$hsnKey]['igst_rate'] = $rate;
                }

                $hsnWiseSummary[$hsnKey]['total_with_gst'] = $hsnWiseSummary[$hsnKey]['taxable_amount'] + $hsnWiseSummary[$hsnKey]['total_gst'];
            }

            // 4) Totals after order discount & GST recalculation
            $totalTaxableAmount = array_sum(array_column($gstSummary, 'taxable_amount'));
            $totalGstAmount = array_sum(array_column($gstSummary, 'total_gst'));
            $totalWithGst = $totalTaxableAmount + $totalGstAmount;

            // Rounding logic (amount payable)
            $roundedTotalWithGst = round($totalWithGst);
            $roundOff = round($roundedTotalWithGst - $totalWithGst, 2);

            if ($roundOff < 0) {
                $roundedTotalWithGst = floor($totalWithGst);
                $roundOff = round($roundedTotalWithGst - $totalWithGst, 2);
            } elseif ($roundOff > 0) {
                $roundedTotalWithGst = ceil($totalWithGst);
                $roundOff = round($roundedTotalWithGst - $totalWithGst, 2);
            }

            // Format gst_summary values
            foreach ($gstSummary as &$gst) {
                $gst['taxable_amount'] = number_format($gst['taxable_amount'], 2, '.', '');
                $gst['cgst'] = number_format($gst['cgst'], 2, '.', '');
                $gst['sgst'] = number_format($gst['sgst'], 2, '.', '');
                $gst['igst'] = number_format($gst['igst'], 2, '.', '');
                $gst['total_gst'] = number_format($gst['total_gst'], 2, '.', '');
                $gst['total_with_gst'] = number_format($gst['total_with_gst'], 2, '.', '');
            }
            unset($gst);

            // Format hsnWiseSummary values
            foreach ($hsnWiseSummary as &$hsnArr) {
                $hsnArr['taxable_amount'] = number_format($hsnArr['taxable_amount'], 2, '.', '');
                $hsnArr['cgst'] = number_format($hsnArr['cgst'], 2, '.', '');
                $hsnArr['sgst'] = number_format($hsnArr['sgst'], 2, '.', '');
                $hsnArr['igst'] = number_format($hsnArr['igst'], 2, '.', '');
                $hsnArr['total_gst'] = number_format($hsnArr['total_gst'], 2, '.', '');
                $hsnArr['total_with_gst'] = number_format($hsnArr['total_with_gst'], 2, '.', '');

                $hsnArr['cgst_rate'] = number_format($hsnArr['cgst_rate'], 2, '.', '');
                $hsnArr['sgst_rate'] = number_format($hsnArr['sgst_rate'], 2, '.', '');
                $hsnArr['igst_rate'] = number_format($hsnArr['igst_rate'], 2, '.', '');
            }
            unset($hsnArr);

            // Prepare final quotation_gst (preserve response shape)
            $quotationGst = [
                'total_taxable_amount' => number_format($totalTaxableAmount, 2, '.', ''),
                'total_cgst' => $isLocal ? number_format($totalCgst, 2, '.', '') : null,
                'total_sgst' => $isLocal ? number_format($totalSgst, 2, '.', '') : null,
                'total_igst' => !$isLocal ? number_format($totalIgst, 2, '.', '') : null,
                'total_gst' => number_format($totalGstAmount, 2, '.', ''),
                'total_with_gst' => number_format($roundedTotalWithGst, 2, '.', ''),
                'round_off' => number_format($roundOff, 2, '.', ''),
                'order_discount' => number_format($orderDiscountTotal, 2, '.', ''),
            ];

            return static::successResponse([
                'quotation' => $quotation,
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
                'quotation_gst' => array_filter($quotationGst),
                'gst_summary' => $gstSummary,
                'hsnwisesummary' => $hsnWiseSummary
            ], 'quotation Fetched');
        } catch (\Exception $e) {
            return static::errorResponse(['Failed to retrieve quotation', $e->getMessage()], 500);
        }
    }


    
    /**
     * Update the specified purchase order.
     */
     public function update(Request $request, $id)
    {
        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Quotation-Generator-Update');
        if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;

        $validator = Validator::make($request->all(), [
            'quotation_no' => 'nullable',
            'quotation_date' => 'nullable|date',
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
            'line_items.*.gst_rate' => 'required|numeric',
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
            return static::errorResponse($validator->errors(), 422);
        }
    
        DB::beginTransaction(); // Start transaction
    
        try {
           $quotation = quotation::with('quotationlineitems')->where('tenant_id', $Id)->where('company_id', $activeCompanyId)->find($id); 

            if(!$quotation) {
                return static::errorResponse(['Invalid quotation ID'], 404);
            }

            $fieldsToUpdate = [];

           // Get order-level discount
            $orderDiscount = $request->order_discount ?? 0;
            
            if ($request->filled('quotation_no') || $request->quotation_no === null || $request->quotation_no === '') {
                $fieldsToUpdate['quotation_no'] = $request->quotation_no;
            }
    
            if ($request->filled('quotation_date') || $request->quotation_date === null || $request->quotation_date === '') {
                $fieldsToUpdate['quotation_date'] = $request->quotation_date;
                $financialYear = $this->getFinancialYear($request->quotation_date);
                $fieldsToUpdate['financial_year'] =  $financialYear;
            }

            if ($request->filled('client_name') || $request->client_name === null || $request->client_name === '') {
                $fieldsToUpdate['client_name'] = $request->client_name;
            }
            if ($request->filled('client_address') || $request->client_address === null || $request->client_address === '') {
                $fieldsToUpdate['client_address'] = $request->client_address;
            }
            if ($request->filled('contact_number') || $request->contact_number === null || $request->contact_number === '') {
                $fieldsToUpdate['contact_number'] = $request->contact_number;
            }
            if ($request->filled('email') || $request->email === null || $request->email === '') {
                $fieldsToUpdate['email'] = $request->email;
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
            $quotation->update($fieldsToUpdate);
    
            // Delete existing line items
            $quotation->quotationlineitems()->delete();

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
                quotationlineitems::create([
                    'quotation_id' => $quotation->id,
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

            $quotation->update([
                'subtotal' => $totalBeforeOrderDiscount,
                'order_discount_amount' => $orderDiscountAmount,
                'total_amount' => $totalAmount,
            ]);
    
            DB::commit(); // Commit transaction if everything is successful

           
            return $this->show($request, $id);
    
        } catch (\Exception $e) {
            DB::rollBack(); // Rollback transaction on failure
            return static::errorResponse(['Failed to update quotation', $e->getMessage()], 500);
        }
    }


    /**
     * Remove the specified purchase order.
     */
    public function destroy(Request $request, $id)
    {
        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Quotation-Generator-Delete');
        if ($permissionResponse) return $permissionResponse;
        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;


        try {
            $quotation = quotation::with('quotationlineitems')->where('tenant_id', $Id)->where('company_id', $activeCompanyId)->find($id); 

            if(!$quotation) {
                return static::errorResponse(['Invalid quotation ID'], 404);
            }

        
            $quotation->quotationlineitems()->delete();
            $quotation->delete();

        

            return static::successResponse(null, 'quotation deleted successfully');
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
            return static::errorResponse(['Failed to delete quotation', $e->getMessage()], 500);
        }
    }

     /**
     * Determine the financial year based on a date.
     * Financial year runs from April 1 to March 31.
     *
     * @param string $date Date in Y-m-d format
     * @return string Financial year in "YY-YY" format (e.g., "23-24")
     */
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

    public function generatequotationPdf($id, Request $request)
    {
        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Quotation-Generator-Show');
        if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;

        $userSetting = usersettings::where('tenant_id',$Id)
            ->where('user_id', $user->id)
            ->get();
            
        // Convert user settings to a key-value array for easier access
        $userSettings = [];
        foreach ($userSetting as $setting) {
            $userSettings[$setting->slug] = $setting->val;
        }

        try {
            // Get quotation details
            $quotation = quotation::with('quotationlineitems')
                ->where('tenant_id', $Id)
                ->where('company_id', $activeCompanyId)
                ->find($id);

            if (!$quotation) {
                return static::errorResponse(['Invalid quotation ID'], 404);
            }

            $company = company::with('state')
                ->where('id', $activeCompanyId)
                ->where('tenant_id',  $Id)
                ->first();

            if (!$company) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Company not found',
                ], 422);
            }

            // Fixed: Use quotation's company_state_id_billing instead of order
            $state = State::find($quotation->company_state_id_billing);
            
            // Determine if CGST & SGST or IGST should be applied
            $isLocal = ($state->id === $company->state->id);

            // Check if tax should be included based on user settings and quotation type
            $includeTax = false;
            if (isset($userSettings['quotation_include_tax']) && $userSettings['quotation_include_tax'] == 'yes') {
                $includeTax = true;
            } 

            // Check if bank details should be included
            $includeBankDetails = false;
            if (isset($userSettings['quotation_include_bank_details']) && $userSettings['quotation_include_bank_details'] == 'yes') {
                $includeBankDetails = true;
            } 

        // 1) First pass: compute taxable after line-item discount for each item
            $itemsAdjusted = [];
            $totalTaxableBeforeOrderDiscount = 0.0;

            foreach ($quotation->quotationlineitems as $item) {
                $gstRate = (float) $item->gst_rate;
                $hsn = $item->product_hsn;
                $taxable = (float) $item->quantity * (float) $item->rate;

                $lineItemDiscount = (float) ($item->line_item_discount ?? 0);
                $lineItemDiscountAmount = ($taxable * $lineItemDiscount) / 100.0;
                $taxableAfterLine = $taxable - $lineItemDiscountAmount;

                // store per-item taxable (after line-item discount) to distribute order discount later
                $itemsAdjusted[] = [
                    'gst_rate' => $gstRate,
                    'hsn' => $hsn,
                    'taxable_before_order' => $taxableAfterLine,
                ];

                $totalTaxableBeforeOrderDiscount += $taxableAfterLine;
            }

            // 2) Compute order-level discount total (applied on taxable sum)
            $orderDiscountPercent = (float) ($quotation->order_discount ?? 0);
            $orderDiscountTotal = 0.0;
            if ($orderDiscountPercent > 0 && $totalTaxableBeforeOrderDiscount > 0) {
                $orderDiscountTotal = ($totalTaxableBeforeOrderDiscount * $orderDiscountPercent) / 100.0;
            }

            // 3) Second pass: apply proportional order discount per item, then compute GST & aggregates
            $gstSummary = [];
            $hsnWiseSummary = [];
            $totalCgst = 0.0;
            $totalSgst = 0.0;
            $totalIgst = 0.0;

            foreach ($itemsAdjusted as $ia) {
                $rate = (float) $ia['gst_rate'];
                $hsn = $ia['hsn'];
                $taxableBefore = (float) $ia['taxable_before_order'];

                // proportional share of order discount
                $share = ($totalTaxableBeforeOrderDiscount > 0) ? ($taxableBefore / $totalTaxableBeforeOrderDiscount) : 0.0;
                $itemOrderDiscount = $orderDiscountTotal * $share;

                // taxable after distributing order discount
                $taxableAfterOrder = $taxableBefore - $itemOrderDiscount;
                if ($taxableAfterOrder < 0) $taxableAfterOrder = 0.0; // guard

                // GST calculations - only if includeTax is true
                $gstAmount = 0.0;
                $cgst = 0.0; 
                $sgst = 0.0; 
                $igst = 0.0;
                
                if ($includeTax) {
                    $gstAmount = ($taxableAfterOrder * $rate) / 100.0;
                    
                    if ($isLocal) {
                        $cgst = $gstAmount / 2.0;
                        $sgst = $gstAmount / 2.0;
                        $totalCgst += $cgst;
                        $totalSgst += $sgst;
                    } else {
                        $igst = $gstAmount;
                        $totalIgst += $igst;
                    }
                }

                // gstSummary keyed by rate as string (to preserve "18" key style)
                $rateKey = (string) (int) $rate;
                if (!isset($gstSummary[$rateKey])) {
                    $gstSummary[$rateKey] = [
                        'taxable_amount' => 0.0,
                        'cgst' => 0.0,
                        'sgst' => 0.0,
                        'igst' => 0.0,
                        'total_gst' => 0.0,
                        'total_with_gst' => 0.0
                    ];
                }

                $gstSummary[$rateKey]['taxable_amount'] += $taxableAfterOrder;
                if ($includeTax) {
                    if ($isLocal) {
                        $gstSummary[$rateKey]['cgst'] += $cgst;
                        $gstSummary[$rateKey]['sgst'] += $sgst;
                        $gstSummary[$rateKey]['total_gst'] += ($cgst + $sgst);
                    } else {
                        $gstSummary[$rateKey]['igst'] += $igst;
                        $gstSummary[$rateKey]['total_gst'] += $igst;
                    }
                }
                $gstSummary[$rateKey]['total_with_gst'] = $gstSummary[$rateKey]['taxable_amount'] + $gstSummary[$rateKey]['total_gst'];

                // HSN-wise aggregation (use same per-item gst split)
                $hsnKey = (string) $hsn;
                if (!isset($hsnWiseSummary[$hsnKey])) {
                    $hsnWiseSummary[$hsnKey] = [
                        'taxable_amount' => 0.0,
                        'cgst' => 0.0,
                        'sgst' => 0.0,
                        'igst' => 0.0,
                        'total_gst' => 0.0,
                        'total_with_gst' => 0.0,
                        'cgst_rate' => 0.0,
                        'sgst_rate' => 0.0,
                        'igst_rate' => 0.0
                    ];
                }

                $hsnWiseSummary[$hsnKey]['taxable_amount'] += $taxableAfterOrder;
                if ($includeTax) {
                    if ($isLocal) {
                        $hsnWiseSummary[$hsnKey]['cgst'] += $cgst;
                        $hsnWiseSummary[$hsnKey]['sgst'] += $sgst;
                        $hsnWiseSummary[$hsnKey]['total_gst'] += ($cgst + $sgst);

                        // set rate fields (if multiple rates for same HSN exist, last one wins — this matches previous pattern)
                        $hsnWiseSummary[$hsnKey]['cgst_rate'] = $rate / 2.0;
                        $hsnWiseSummary[$hsnKey]['sgst_rate'] = $rate / 2.0;
                        $hsnWiseSummary[$hsnKey]['igst_rate'] = 0.0;
                    } else {
                        $hsnWiseSummary[$hsnKey]['igst'] += $igst;
                        $hsnWiseSummary[$hsnKey]['total_gst'] += $igst;

                        $hsnWiseSummary[$hsnKey]['cgst_rate'] = 0.0;
                        $hsnWiseSummary[$hsnKey]['sgst_rate'] = 0.0;
                        $hsnWiseSummary[$hsnKey]['igst_rate'] = $rate;
                    }
                }

                $hsnWiseSummary[$hsnKey]['total_with_gst'] = $hsnWiseSummary[$hsnKey]['taxable_amount'] + $hsnWiseSummary[$hsnKey]['total_gst'];
            }

            // 4) Totals after order discount & GST recalculation
            $totalTaxableAmount = array_sum(array_column($gstSummary, 'taxable_amount'));
            $totalGstAmount = $includeTax ? array_sum(array_column($gstSummary, 'total_gst')) : 0.0;
            $totalWithGst = $totalTaxableAmount + $totalGstAmount;

            // Rounding logic (amount payable)
            $roundedTotalWithGst = round($totalWithGst);
            $roundOff = round($roundedTotalWithGst - $totalWithGst, 2);

            if ($roundOff < 0) {
                $roundedTotalWithGst = floor($totalWithGst);
                $roundOff = round($roundedTotalWithGst - $totalWithGst, 2);
            } elseif ($roundOff > 0) {
                $roundedTotalWithGst = ceil($totalWithGst);
                $roundOff = round($roundedTotalWithGst - $totalWithGst, 2);
            }

            // Format gst_summary values
            foreach ($gstSummary as &$gst) {
                $gst['taxable_amount'] = number_format($gst['taxable_amount'], 2, '.', '');
                $gst['cgst'] = number_format($gst['cgst'], 2, '.', '');
                $gst['sgst'] = number_format($gst['sgst'], 2, '.', '');
                $gst['igst'] = number_format($gst['igst'], 2, '.', '');
                $gst['total_gst'] = number_format($gst['total_gst'], 2, '.', '');
                $gst['total_with_gst'] = number_format($gst['total_with_gst'], 2, '.', '');
            }
            unset($gst);

            // Format hsnWiseSummary values
            foreach ($hsnWiseSummary as &$hsnArr) {
                $hsnArr['taxable_amount'] = number_format($hsnArr['taxable_amount'], 2, '.', '');
                $hsnArr['cgst'] = number_format($hsnArr['cgst'], 2, '.', '');
                $hsnArr['sgst'] = number_format($hsnArr['sgst'], 2, '.', '');
                $hsnArr['igst'] = number_format($hsnArr['igst'], 2, '.', '');
                $hsnArr['total_gst'] = number_format($hsnArr['total_gst'], 2, '.', '');
                $hsnArr['total_with_gst'] = number_format($hsnArr['total_with_gst'], 2, '.', '');

                $hsnArr['cgst_rate'] = number_format($hsnArr['cgst_rate'], 2, '.', '');
                $hsnArr['sgst_rate'] = number_format($hsnArr['sgst_rate'], 2, '.', '');
                $hsnArr['igst_rate'] = number_format($hsnArr['igst_rate'], 2, '.', '');
            }
            unset($hsnArr);

            // Prepare final quotation_gst (preserve response shape)
            $quotationGst = [
                'total_taxable_amount' => number_format($totalTaxableAmount, 2, '.', ''),
                'total_cgst' => $isLocal && $includeTax ? number_format($totalCgst, 2, '.', '') : null,
                'total_sgst' => $isLocal && $includeTax ? number_format($totalSgst, 2, '.', '') : null,
                'total_igst' => !$isLocal && $includeTax ? number_format($totalIgst, 2, '.', '') : null,
                'total_gst' => number_format($totalGstAmount, 2, '.', ''),
                'total_with_gst' => number_format($roundedTotalWithGst, 2, '.', ''),
                'round_off' => number_format($roundOff, 2, '.', ''),
                'order_discount' => number_format($orderDiscountTotal, 2, '.', ''),
            ];
            
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

            $templateView = ($templateNumber == 2) ? 'pdf.quotationtemplate2' : 'pdf.quotationtemplate1';
            
            // Prepare view data
            $viewData = [
                'quotation' => $quotation,
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
                'quotation_gst' => array_filter($quotationGst),
                'gst_summary' => $gstSummary,
                'hsnwisesummary' => $hsnWiseSummary,
                'includeTax' => $includeTax,
                'includeBankDetails' => $includeBankDetails,
                'bankDetails' => $bankDetails,
                'isLocal' => $isLocal
            ];

            // DEBUG: Uncomment any of these lines to debug the data
            //dd($viewData); // This will stop execution and show all data
            // dump($viewData); // This will show data but continue execution
            // \Log::info('View Data Debug:', $viewData); // This will log to laravel.log
            
            // Render the view
            $view = view($templateView, $viewData)->render();

            // Initialize Dompdf
            $pdf = new Dompdf($options);

            // Load HTML content
            $pdf->loadHtml($view, 'UTF-8');

            // Set paper size and orientation
            $pdf->setPaper('A4', 'portrait');

            // Render the PDF
            $pdf->render();

            // Output PDF content
            $output = $pdf->output();

            // Return response with correct headers - Fixed: Use quotation->id instead of order->id
            return response($output, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="quotation_' . $quotation->id . '.pdf"');

        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to generate Quotation PDF: ' . $e->getMessage(),
            ], 500);
        }
    }


}

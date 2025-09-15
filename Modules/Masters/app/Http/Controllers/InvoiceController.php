<?php

namespace Modules\Masters\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Models\invoice;
use App\Models\invoicelineitems;
use App\Models\State;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Http;
use App\Models\company;

class InvoiceController extends ApiController
{
    public function index(Request $request)
    {

        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Invoice-Generator-Show');
        if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;

        try {
            $perPage = $request->input('per_page', 10);
            $page = $request->input('page', 1);
            $query = invoice::with('invoicelineitems')->where('tenant_id', $Id)->where('company_id', $activeCompanyId);
            $search = $request->input('search');

             // Get the authenticated user
             $user = auth()->user();

          
            if ($request->filled('date_from') && $request->filled('date_to')) {
                $query->whereBetween('invoice_date', [
                    $request->input('date_from'),
                    $request->input('date_to')
                ]);
            }

          
            

            if ($search !== null && $search !== '')
            {
                
                $query->where('invoice_no', 'like', "%{$search}%")
                ->orWhere('company_name_billing', 'like', "%{$search}%")
                ->orWhere('company_name_shipping', 'like', "%{$search}%");
            }

            $query->orderBy('id', 'desc');
            
           

            $invoices = $query->paginate((int)$perPage, ['*'], 'page', (int)$page);
            return $this->paginatedResponse($invoices, 'Invoice retrieved successfully');
        } catch (\Exception $e) {
            return static::errorResponse(['Failed to retrieve Invoice', $e->getMessage()], 500);
        }
    }

   
    public function store(Request $request)
    {
        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Invoice-Generator-Insert');
        if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;

        $validator = Validator::make($request->all(), [
            'invoice_no' => 'required',
            'invoice_date' => 'required|date',
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
            $financialYear =  $this->getFinancialYear($request->invoice_date);
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

            $Data = [
                'tenant_id' => $Id,
                'company_id' => $activeCompanyId,
                'invoice_no' =>  $request->invoice_no,
                'invoice_date' => $request->invoice_date,
                'financial_year'=> $financialYear,
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
            ];

            $Invoice = invoice::create($Data);

           

            foreach ($request->line_items as $item) {
                // Calculate GST value
                $gstRate = $item['gst_rate'] ?? 0; // Default to 0 if not provided
                $baseAmount = $item['rate'] * $item['quantity']; 
                $gstValue = ($baseAmount * $gstRate) / 100; // Calculate GST amount
                
                // Calculate total amount including GST
                $calculatedAmount = $baseAmount + $gstValue;
            
                invoicelineitems::create([
                    'invoice_id' => $Invoice->id,
                    'product_name' => $item['product_name'],
                    'product_description' => $item['product_description'] ?? null,
                    'product_hsn' => $item['product_hsn'] ?? null,
                    'quantity' => $item['quantity'],
                    'rate' => $item['rate'],
                    'gst_rate' => $gstRate,
                    'gst_value' => $gstValue,
                    'unit' => $item['unit'] ?? null,
                    'amount' => $baseAmount,
                    'amount_with_gst' => $calculatedAmount,
                ]);
            }

           

            DB::commit();
            return $this->show($request,$Invoice->id);
        } catch (\Exception $e) {
            DB::rollBack();
            return static::errorResponse(['Failed to create Invoice', $e->getMessage()], 500);
        }
    }


   
    public function show(Request $request, $id)
    {
        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Invoice-Generator-Show');
        if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;

        try {
            $Invoice = invoice::with('invoicelineitems')->where('tenant_id', $Id)->where('company_id', $activeCompanyId)->find($id); 

            if(!$Invoice) {
                return static::errorResponse(['Invalid Invoice ID'], 404);
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

            $state = State::find($Invoice->company_state_id_billing);

            // Determine if CGST & SGST or IGST should be applied
            $isLocal = ($state->title === $company->state->title);

            // Initialize total GST values
            $totalCgst = 0;
            $totalSgst = 0;
            $totalIgst = 0;

            // Initialize GST summary array
            $gstSummary = [];

            foreach ($Invoice->invoicelineitems as $item) {
                $gstRate = $item->gst_rate;
                $taxableAmount = $item->quantity * $item->rate;
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

                // Add GST rate-wise breakdown
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

            // Prepare order GST totals
            $totalTaxableAmount = array_sum(array_column($gstSummary, 'taxable_amount'));
            $totalGstAmount = array_sum(array_column($gstSummary, 'total_gst'));
            $totalWithGst = $totalTaxableAmount + $totalGstAmount;

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

            // Format values to 2 decimal places
            $InvoiceGst = [
                'total_taxable_amount' => number_format($totalTaxableAmount, 2, '.', ''),
                'total_cgst' => $isLocal ? number_format($totalCgst, 2, '.', '') : null,
                'total_sgst' => $isLocal ? number_format($totalSgst, 2, '.', '') : null,
                'total_igst' => !$isLocal ? number_format($totalIgst, 2, '.', '') : null,
                'total_gst' => number_format($totalGstAmount, 2, '.', ''),
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

              // Hsn wise summary
            $hsnWiseSummary = [];

            foreach ($Invoice->invoicelineitems as $item) {
                $hsn = $item->product_hsn;
                $gstRate = $item->gst_rate;
                $taxableAmount = $item->quantity * $item->rate;
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
                'invoice' => $Invoice,
                'invoice_gst' => array_filter($InvoiceGst), // Remove null values
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
                ]
            ], 'Invoice Fetched');
        } catch (\Exception $e) {
            return static::errorResponse(['Failed to retrieve Invoice', $e->getMessage()], 500);
        }
    }


    
    
    public function update(Request $request, $id)
    {
        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Invoice-Generator-Update');
        if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;

        $validator = Validator::make($request->all(), [
            'invoice_no' => 'nullable',
            'invoice_date' => 'nullable|date',
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
            $Invoice = invoice::with('invoicelineitems')->where('tenant_id', $Id)->where('company_id', $activeCompanyId)->find($id); 

            if(!$Invoice) {
                return static::errorResponse(['Invalid Invoice ID'], 404);
            }

            $fieldsToUpdate = [];

    
            
            if ($request->filled('invoice_no') || $request->invoice_no === null || $request->invoice_no === '') {
                $fieldsToUpdate['invoice_no'] = $request->invoice_no;
            }
    
            if ($request->filled('invoice_date') || $request->invoice_date === null || $request->invoice_date === '') {
                $fieldsToUpdate['invoice_date'] = $request->invoice_date;
                $financialYear = $this->getFinancialYear($request->invoice_date);
                $fieldsToUpdate['financial_year'] =  $financialYear;
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
    
            // Update order with provided fields
            $Invoice->update($fieldsToUpdate);
    
            // Delete existing line items
            $Invoice->invoicelineitems()->delete();
    
            // Create new line items
            foreach ($request->line_items as $item) {
                $gstRate = $item['gst_rate'] ?? 0; 
                $baseAmount = $item['rate'] * $item['quantity']; 
                $gstValue = ($baseAmount * $gstRate) / 100; 
                $calculatedAmount = $baseAmount + $gstValue;
    
                invoicelineitems::create([
                    'invoice_id' => $Invoice->id,
                    'product_name' => $item['product_name'],
                    'product_description' => $item['product_description'] ?? null,
                    'product_hsn' => $item['product_hsn'] ?? null,
                    'quantity' => $item['quantity'],
                    'rate' => $item['rate'],
                    'gst_rate' => $gstRate,
                    'gst_value' => $gstValue,
                    'unit' => $item['unit'] ?? null,
                    'amount' => $baseAmount,
                    'amount_with_gst' => $calculatedAmount,
                ]);
            }
    
            DB::commit(); // Commit transaction if everything is successful

           
            return $this->show($request, $id);
    
        } catch (\Exception $e) {
            DB::rollBack(); // Rollback transaction on failure
            return static::errorResponse(['Failed to update  Invoice', $e->getMessage()], 500);
        }
    }


    
    public function destroy(Request $request, $id)
    {
        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Invoice-Generator-Delete');
        if ($permissionResponse) return $permissionResponse;
        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;


        try {
            $Invoice = invoice::with('invoicelineitems')->where('tenant_id', $Id)->where('company_id', $activeCompanyId)->find($id); 
            if(!$Invoice) {
                return static::errorResponse(['Invalid Invoice ID'], 404);
            }

        
            $Invoice->invoicelineitems()->delete();
            $Invoice->delete();

        

            return static::successResponse(null, 'Invoice deleted successfully');
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
            return static::errorResponse(['Failed to delete Invoice', $e->getMessage()], 500);
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

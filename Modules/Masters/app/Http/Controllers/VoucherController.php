<?php

namespace Modules\Masters\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Voucher;
use App\Models\Vouchermeta;
use App\Models\Product;
use App\Models\Item;
use App\Models\Itemmeta;
use App\Models\productstock;
use Log;
use Illuminate\Support\Facades\DB;
use App\Models\usersettings;
use Carbon\Carbon;
use App\Models\FinancialYear;
use App\Models\company;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class VoucherController extends Controller
{
    private function getFinancialYear(Carbon $date)
    {
        $year = $date->year;
        $month = $date->month;

        // Determine financial year range
        if ($month >= 4) {
            $startYear = $year;
            $endYear = $year + 1;
        } else {
            $startYear = $year - 1;
            $endYear = $year;
        }

        $financialYearName = "{$startYear}-{$endYear}";

        return FinancialYear::where('year', $financialYearName)->first();
    }

    public function generateVoucherNumber(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        $validator = Validator::make($request->all(), [
            'transaction_date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $transactionDate = Carbon::parse($request->transaction_date);

            // Calculate financial year using getFinancialYear method
            $financialYear = $this->getFinancialYear($transactionDate);

            if (empty($financialYear)) {
                return response()->json([
                    'status' => 422,
                    'message' => 'This financial year transaction is not allowed as per system'
                ], 422);
            }

            // Step 1: Check if voucher_auto_voucher_number is enabled
            $autoVoucherSetting = usersettings::where('tenant_id', $tenantId)
                ->where('user_id', $user->id)
                ->where('slug', 'voucher_auto_voucher_number')
                ->first();

            if (!$autoVoucherSetting || $autoVoucherSetting->val !== 'yes') {
                return response()->json([
                    'status' => 422,
                    'message' => 'Cannot auto generate voucher number'
                ], 422);
            }

            // Step 2: Get highest voucher_no from voucher table for that financial year
            $highestVoucher = Voucher::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->where('financial_year_id', $financialYear->id)
                ->whereIn('transaction_type', ['outward','s_outward'])
                ->whereNotNull('voucher_no')
                ->whereRaw('voucher_no REGEXP "^[0-9]+$"') // Only purely numeric voucher numbers
                ->orderByRaw('CAST(voucher_no AS UNSIGNED) DESC')
                ->first();

            //dd($highestVoucher);

            $nextVoucherNumber = $highestVoucher ? (int)$highestVoucher->voucher_no + 1 : 1;

            // Step 3: Check voucher prefix settings
            $includePrefixSetting = usersettings::where('tenant_id', $tenantId)
                ->where('user_id', $user->id)
                ->where('slug', 'voucher_include_prefix')
                ->first();

            $voucherPrefix = '';
            $financialYearSlug = '';
            $combinedVoucherNumber = (string)$nextVoucherNumber;

            if ($includePrefixSetting && $includePrefixSetting->val === 'yes') {
                // Get voucher prefix
                $prefixSetting = usersettings::where('tenant_id', $tenantId)
                    ->where('user_id', $user->id)
                    ->where('slug', 'voucher_prefix')
                    ->first();

                $voucherPrefix = $prefixSetting ? $prefixSetting->val : '';

                // Check if financial year should be included
                $includeFinancialYearSetting = usersettings::where('tenant_id', $tenantId)
                    ->where('user_id', $user->id)
                    ->where('slug', 'voucher_include_financial_year')
                    ->first();

                if ($includeFinancialYearSetting && $includeFinancialYearSetting->val === 'yes') {
                    $financialYearSlug = $financialYear->slug;
                }
            }

            //dd($financialYear);

            // Step 4: Build combined voucher number
            $combinedParts = array_filter([
                $voucherPrefix,
                $financialYearSlug,
                $nextVoucherNumber
            ]);

            $combinedVoucherNumber = implode('/', $combinedParts);

             $combinedPartsprefix = array_filter([
                $voucherPrefix,
                $financialYearSlug
            ]);

            $combinedVoucherprefix = implode('/', $combinedPartsprefix);

            // Return the response
            return response()->json([
                'status' => 200,
                'message' => 'Voucher number generated successfully',
                'data' => [
                    'voucher_prefix' => $voucherPrefix,
                    'financial_year_slug' => $financialYearSlug,
                    'auto_generated_voucher_number' => $nextVoucherNumber,
                    'combined_prefix' =>  $combinedVoucherprefix,
                    'combined_voucher_number' => $combinedVoucherNumber,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to generate voucher number: ' . $e->getMessage(),
            ], 500);
        }
    }


    public function index(Request $request)
    {
        $response = $this->checkPermission('Voucher-Show');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();
    
        // Get per_page parameter or default to 10
        $perPage = $request->input('per_page', 10);
        $search = $request->input('search');
        // Fetch filter parameters
        $partyId = $request->input('party_id');
        $transactionType = $request->input('transaction_type');
        $itemId =  $request->input('item_id');
        $transactionDateFrom = $request->input('transaction_date_from');
        $transactionDateTo = $request->input('transaction_date_to');
    
        // Build the query
        $query = Voucher::with('party','Vouchermeta.category', 'Vouchermeta.Item')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->orderBy('transaction_date', 'desc');

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('voucher_no', 'like', "%{$search}%")
                        ->orWhere('vehicle_number', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            }   
    
        // Apply filters
        if ($partyId) {
            $query->where('party_id', $partyId);
        }
        if ($transactionType) {
            $query->where('transaction_type', $transactionType);
        }

        if ($itemId) {
            $query->whereHas('Vouchermeta', function ($query) use ($itemId) {
                $query->where('item_id', $itemId);
            });
        }

        if ($transactionDateFrom && $transactionDateTo) {
            $query->whereBetween('transaction_date', [$transactionDateFrom, $transactionDateTo]);
        } elseif ($transactionDateFrom) {
            $query->where('transaction_date', '>=', $transactionDateFrom);
        } elseif ($transactionDateTo) {
            $query->where('transaction_date', '<=', $transactionDateTo);
        }
    
        
        // Execute paginated query
        $vouchers = $query->paginate($perPage);
    
        // Return response with pagination metadata
        return response()->json([
            'status' => 200,
            'message' => 'Vouchers retrieved successfully',
            'records' => $vouchers->items(), // Paginated records
            'pagination' => [
                'current_page' => $vouchers->currentPage(),
                'total_count' => $vouchers->total(),
                'per_page' => $vouchers->perPage(),
                'last_page' => $vouchers->lastPage(),
            ],
        ]);
    }

    public function indexinhouse(Request $request)
    {
        $response = $this->checkPermission('Voucher-Show');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();
    
        // Get per_page parameter or default to 10
        $perPage = $request->input('per_page', 10);
        $search = $request->input('search');
        // Fetch filter parameters
        $partyId = $request->input('party_id');
        $transactionType = $request->input('transaction_type');
        $transactionDateFrom = $request->input('transaction_date_from');
        $transactionDateTo = $request->input('transaction_date_to');
        $itemId =  $request->input('item_id');
        // Build the query
        $query = Voucher::with('party','Vouchermeta.category', 'Vouchermeta.Item')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->whereIn('transaction_type', ['inward','outward','adjustment'])
            ->orderBy('transaction_date', 'desc');

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('voucher_no', 'like', "%{$search}%")
                        ->orWhere('vehicle_number', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            }   
    
        // Apply filters
        if ($partyId) {
            $query->where('party_id', $partyId);
        }
        if ($transactionType) {
            $query->where('transaction_type', $transactionType);
        }

        if ($itemId) {
            $query->whereHas('Vouchermeta', function ($query) use ($itemId) {
                $query->where('item_id', $itemId);
            });
        }
        if ($transactionDateFrom && $transactionDateTo) {
            $query->whereBetween('transaction_date', [$transactionDateFrom, $transactionDateTo]);
        } elseif ($transactionDateFrom) {
            $query->where('transaction_date', '>=', $transactionDateFrom);
        } elseif ($transactionDateTo) {
            $query->where('transaction_date', '<=', $transactionDateTo);
        }
    
        // Execute paginated query
        $vouchers = $query->paginate($perPage);
    
        // Return response with pagination metadata
        return response()->json([
            'status' => 200,
            'message' => 'Vouchers retrieved successfully',
            'records' => $vouchers->items(), // Paginated records
            'pagination' => [
                'current_page' => $vouchers->currentPage(),
                'total_count' => $vouchers->total(),
                'per_page' => $vouchers->perPage(),
                'last_page' => $vouchers->lastPage(),
            ],
        ]);
    }
    
    public function indexsubcontract(Request $request)
    {
        $response = $this->checkPermission('Voucher-Show');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();
    
        // Get per_page parameter or default to 10
        $perPage = $request->input('per_page', 10);
        $search = $request->input('search');
        // Fetch filter parameters
        $partyId = $request->input('party_id');
        $transactionType = $request->input('transaction_type');
        $transactionDateFrom = $request->input('transaction_date_from');
        $transactionDateTo = $request->input('transaction_date_to');
        $itemId =  $request->input('item_id');
        // Build the query
        $query = Voucher::with('party','Vouchermeta.category', 'Vouchermeta.Item')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->whereIn('transaction_type', ['s_inward','s_outward','s_adjustment'])
             ->orderBy('transaction_date', 'desc');

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('voucher_no', 'like', "%{$search}%")
                        ->orWhere('vehicle_number', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            }   
    
        // Apply filters
        if ($partyId) {
            $query->where('party_id', $partyId);
        }
        if ($transactionType) {
            $query->where('transaction_type', $transactionType);
        }

        if ($itemId) {
            $query->whereHas('Vouchermeta', function ($query) use ($itemId) {
                $query->where('item_id', $itemId);
            });
        }

        if ($transactionDateFrom && $transactionDateTo) {
            $query->whereBetween('transaction_date', [$transactionDateFrom, $transactionDateTo]);
        } elseif ($transactionDateFrom) {
            $query->where('transaction_date', '>=', $transactionDateFrom);
        } elseif ($transactionDateTo) {
            $query->where('transaction_date', '<=', $transactionDateTo);
        }
    
        // Execute paginated query
        $vouchers = $query->paginate($perPage);
    
        // Return response with pagination metadata
        return response()->json([
            'status' => 200,
            'message' => 'Vouchers retrieved successfully',
            'records' => $vouchers->items(), // Paginated records
            'pagination' => [
                'current_page' => $vouchers->currentPage(),
                'total_count' => $vouchers->total(),
                'per_page' => $vouchers->perPage(),
                'last_page' => $vouchers->lastPage(),
            ],
        ]);
    }

    public function show($id, Request $request)
    {
        $response = $this->checkPermission('Voucher-Show');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        $voucher = Voucher::with('party','Vouchermeta.category', 'Vouchermeta.Item')
        ->where('tenant_id', $tenantId)
        ->where('company_id', $activeCompanyId)
        ->where('id',$id)
        ->first();

        if (!$voucher) {
            return response()->json([
                'status' => 200,
                'message' => 'voucher not found',
            ], 200);
        }

        $voucher->load('Vouchermeta.category', 'Vouchermeta.Item');

        return response()->json([
            'status' => 200,
            'message' => 'Voucher retrieved successfully',
            'voucher' => $voucher,
        ]);
    }

    public function store(Request $request)
    {
        $response = $this->checkPermission('Voucher-Insert');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();
    
        // Fetch user settings
        $userSetting = usersettings::where('tenant_id', $tenantId)
            ->where('slug', 'transaction_time')
            ->first();
    
        $validator = Validator::make($request->all(), [
            'party_id' => 'required|exists:party,id',
            'transaction_type' => 'required|in:inward,outward,adjustment,s_inward,s_outward,s_adjustment',
            'transaction_date' => 'nullable|date',
            'transaction_time' => 'nullable|date_format:H:i:s',
            'issue_date' => 'nullable|date|date_format:Y-m-d H:i:s',
            'voucher_no' => 'nullable',
            'transporter_id' => 'nullable|exists:transporter,id',
            'vehicle_number' => 'nullable|string',
            'description' => 'nullable|string',
            'item' => 'required|array',
            'item.*.category_id' => 'required|exists:item_category,id',
            'item.*.item_parent_id' => [
                'nullable',
                'exists:item,id',
            ],
            'item.*.item_id' => 'required|exists:item,id',
            'item.*.item_quantity' => 'sometimes|numeric',
            'item.*.remark' => 'sometimes|string|nullable',
        ]);

        $validator->after(function ($validator) use ($request) {
            if (in_array($request->transaction_type, ['outward', 's_inward'])) {
                foreach ($request->item as $index => $item) {
                    if (!empty($item['item_parent_id'])) {
                        $childItem = Item::where('id', $item['item_id'])
                            ->where('parent_id', $item['item_parent_id'])
                            ->first();
        
                        if (!$childItem) {
                            $validator->errors()->add("item.$index.item_id", "The selected item does not belong to the specified parent item.");
                        }
                    }
                }
            }
        });

        $validator->validate(); // Now run validation with custom rules

        $data = $validator->validated(); // Access validated data
    
        if (!empty($data['transaction_date'])) {
            $transactionDatecheck = Carbon::parse($data['transaction_date']);
    
            // Extract year, month, and day
            $year = $transactionDatecheck->year;
            $month = $transactionDatecheck->month;
            $day = $transactionDatecheck->day;
    
            // Use checkdate to validate
            if (!checkdate($month, $day, $year)) {
                return response()->json([
                    'error' => 'The transaction_date is invalid.'
                ], 422);
            }
        }

        
            $includePrefixSetting = usersettings::where('tenant_id', $tenantId)
                ->where('user_id', $user->id)
                ->where('slug', 'voucher_include_prefix')
                ->first();

            $voucherPrefix = '';

            if ($includePrefixSetting && $includePrefixSetting->val === 'yes' && 
            in_array($data['transaction_type'], ['outward', 's_outward'])) {
                // Get voucher prefix
                $prefixSetting = usersettings::where('tenant_id', $tenantId)
                    ->where('user_id', $user->id)
                    ->where('slug', 'voucher_prefix')
                    ->first();

                $voucherPrefix = $prefixSetting ? $prefixSetting->val : '';
            }

        try {
            DB::beginTransaction();
    
             // Set default date and time if not provided
            $transactionDate = isset($data['transaction_date']) 
            ? Carbon::parse($data['transaction_date'])
            : Carbon::today();

            $transactionTime = isset($data['transaction_time'])
            ? $data['transaction_time']
            : '08:00';

            $issueDate = isset($data['issue_date']) 
            ? Carbon::parse($data['issue_date'])
            : now();


            $financialYear = $this->getFinancialYear($transactionDate);

            if (empty($financialYear)) {
               
                 return response()->json([
                                'status' => 422,
                                'message' => 'This financial year transaction is not allowed as per system'
                            ], 422);
            }

            $validateissueDate = isset($data['issue_date']) 
            ? Carbon::parse($data['issue_date']) 
            : now();

            $financialissueYear = $this->getFinancialYear($validateissueDate);

            if (empty($financialissueYear)) {
          
                         return response()->json([
                                'status' => 422,
                                'message' => 'This financial year issue date is not allowed as per system'
                            ], 422);
                
            }




           
    
            // Create voucher
            $voucher = Voucher::create([
                'tenant_id' => $tenantId,
                'company_id' => $activeCompanyId,
                'party_id' => $data['party_id'],
                'transaction_type' => $data['transaction_type'],
                'transaction_date' => $transactionDate,
                'transaction_time' => $transactionTime,
                'issue_date' =>   $issueDate,
                'financial_year_id' => $financialYear->id,
                'prefix'=> $voucherPrefix,
                'voucher_no' => $data['voucher_no']??null,
                'transporter_id' => $data['transporter_id']??null,
                'vehicle_number' => $data['vehicle_number']??null,
                'description' => $data['description']??null,
            ]);
    
            // Create voucher_meta records
            foreach ($data['item'] as $productData) {
                $product = Item::find($productData['item_id']);
    
                 // Check if material_price is null
                if (is_null($product->material_price)) {
                   $message="Material price must be set for item ID: {$product->name} before creating voucher";
                     return response()->json([
                                'status' => 422,
                                'message' =>$message
                            ], 422);
                }
                $remark = null;
                if (in_array($data['transaction_type'], ['outward', 's_inward'])) {
                    if (empty($productData['remark'])) {
                       
                         $message="Remark is required for transaction type: {$data['transaction_type']}";
                         return response()->json([
                                'status' => 422,
                                'message' =>$message
                            ], 422);
                    }
                    $remark = $productData['remark'];
                }

                $jobworkrate= $product->getLatestJobworkRate($transactionDate);
               
                $scrap_wt= $product->getLatestScrapWeight($transactionDate);
                
    
                Vouchermeta::create([
                    'tenant_id' => $tenantId,
                    'voucher_id' => $voucher->id,
                    'category_id' => $productData['category_id'],
                    'item_id' => $productData['item_id'],
                    'item_parent_id' => $productData['item_parent_id']??null,
                    'item_quantity' => $productData['item_quantity'],
                    'job_work_rate' => $jobworkrate,
                    'scrap_wt' => $scrap_wt,
                    'material_price' => $product->material_price,
                    'gst_percent_rate' => $product->gst_percent_rate,
                    'remark' => $remark,
                ]);

               
                // $this->calculateAndUpdateStock(
                //     $tenantId,
                //     $activeCompanyId, 
                //     $productData['item_id'],
                //     in_array($data['transaction_type'], ['s_inward', 's_outward', 's_adjustment']) ? $data['party_id'] : null,
                //     $financialYear->id
                // );
                
            }

            $vouchercreated = Voucher::with('party','Vouchermeta.category', 'Vouchermeta.Item')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->where('id',$voucher->id)
            ->first();
    
            DB::commit();


    
            return response()->json([
                'status' => 201,
                'message' => 'Voucher created successfully',
                'voucher' =>  $vouchercreated,
            ]);
    
        } catch (\Exception $e) {
            DB::rollBack();
    
            return response()->json([
                'status' => 500,
                'message' => 'Failed to create voucher: ' . $e->getMessage(),
            ], 500);
        }
    }
    
    
    public function update($id, Request $request)
    {
        $response = $this->checkPermission('Voucher-Update');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();
    
        $voucher = Voucher::with('Vouchermeta.category', 'Vouchermeta.Item')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->where('id', $id)
            ->first();
    
        if (!$voucher) {
            return response()->json([
                'status' => 200,
                'message' => 'Voucher not found',
            ], 200);
        }
    
        // Fetch user settings
        $userSetting = usersettings::where('tenant_id', $tenantId)
            ->where('slug', 'transaction_time')
            ->first();
    
        $validator = Validator::make($request->all(), [
            'party_id' => 'sometimes|exists:party,id',
            'transaction_type' => 'sometimes|in:inward,outward,adjustment,s_inward,s_outward,s_adjustment',
            'transaction_date' => 'sometimes|date',
            'transaction_time' => 'nullable|date_format:H:i:s',
            'issue_date' => 'nullable|date|date_format:Y-m-d H:i:s',
            'transporter_id' => 'nullable|exists:transporter,id',
            'vehicle_number' => 'nullable|string',
            'description' => 'nullable|string',
            'item' => 'nullable|array',
            'item.*.category_id' => 'required_with:item|exists:item_category,id',
            'item.*.item_parent_id' => [
                'nullable',
                'exists:item,id',
            ],
            'item.*.item_id' => 'required_with:item|exists:item,id',
            'item.*.item_quantity' => 'sometimes|numeric',
            'item.*.remark' => 'sometimes|string|nullable',
        ]);

        $validator->after(function ($validator) use ($request) {
            if (in_array($request->transaction_type, ['outward', 's_inward'])) {
                foreach ($request->item as $index => $item) {
                    if (!empty($item['item_parent_id'])) {
                        $childItem = Item::where('id', $item['item_id'])
                            ->where('parent_id', $item['item_parent_id'])
                            ->first();
        
                        if (!$childItem) {
                            $validator->errors()->add("item.$index.item_id", "The selected item does not belong to the specified parent item.");
                        }
                    }
                }
            }
        });

        $validator->validate(); // Now run validation with custom rules

        $data = $validator->validated(); // Access validated data

        if (!empty($data['transaction_date'])) {
            $transactionDatecheck = Carbon::parse($data['transaction_date']);
    
            // Extract year, month, and day
            $year = $transactionDatecheck->year;
            $month = $transactionDatecheck->month;
            $day = $transactionDatecheck->day;
    
            // Use checkdate to validate
            if (!checkdate($month, $day, $year)) {
                return response()->json([
                    'error' => 'The transaction_date is invalid.'
                ], 422);
            }
        }
    
        try {
            DB::beginTransaction();
    
            

             // Update financial year if transaction_date changes
            if (isset($data['transaction_date'])) {
                $transactionDate = Carbon::parse($data['transaction_date']);
                $financialYear = $this->getFinancialYear($transactionDate);
                if (empty($financialYear)) {
                  
                    
                     return response()->json([
                                'status' => 422,
                                'message' =>"This financial year transaction is not allowed as per system"
                            ], 422);
                }
                $voucher->financial_year_id = $financialYear->id;
                $voucher->transaction_date = $transactionDate;
            }

            // Update transaction_time
            if (isset($data['transaction_time'])) {
                $voucher->transaction_time = $data['transaction_time'];
            } else if (isset($data['transaction_date']) && !$voucher->transaction_time) {
                // If date is being updated but no time exists, set default time to 8 AM
                $voucher->transaction_time = '08:00';
            }

            if (isset($data['issue_date'])) {
                $issueDate = Carbon::parse($data['issue_date']);
                $financialYear = $this->getFinancialYear($issueDate);
                if (empty($financialYear)) {
                     return response()->json([
                                'status' => 422,
                                'message' =>"This financial year issue date is not allowed as per system"
                            ], 422);
                }
                 
                $voucher->issue_date = $issueDate;
            } 


    
            // Update other voucher fields
            if (isset($data['party_id'])) $voucher->party_id = $data['party_id'];
            if (isset($data['transaction_type'])) $voucher->transaction_type = $data['transaction_type'];
            if (isset($data['transporter_id'])) $voucher->transporter_id = $data['transporter_id'];
            if (isset($data['vehicle_number'])) $voucher->vehicle_number = $data['vehicle_number'];
            if (isset($data['description'])) $voucher->description = $data['description'];
    
            $voucher->save();
    
            if (isset($data['item'])) {
                // Delete old Vouchermeta records
                $voucher->Vouchermeta()->delete();
    
                foreach ($data['item'] as $productData) {
                    $product = Item::find($productData['item_id']);
                     // Check if material_price is null
                    if (is_null($product->material_price)) {
                      $message="Material price must be set for item ID: {$productData['item_id']} before creating voucher";
                          return response()->json([
                                'status' => 422,
                                'message' => $message
                            ], 422);
                    }
                    $remark = null;
                    if (in_array($data['transaction_type'], ['outward', 's_inward'])) {
                        if (empty($productData['remark'])) {
                           $message="Remark is required for transaction type: {$data['transaction_type']}";
                              return response()->json([
                                'status' => 422,
                                'message' =>$message
                            ], 422);
                        }
                        $remark = $productData['remark'];
                    }
    
                    $jobworkrate= $product->getLatestJobworkRate($transactionDate);
               
                    $scrap_wt= $product->getLatestScrapWeight($transactionDate);
                    
        
                    Vouchermeta::create([
                        'tenant_id' => $tenantId,
                        'voucher_id' => $voucher->id,
                        'category_id' => $productData['category_id'],
                        'item_parent_id' => $productData['item_parent_id']??null,
                        'item_id' => $productData['item_id'],
                        'item_quantity' => $productData['item_quantity'],
                        'job_work_rate' => $jobworkrate,
                        'scrap_wt' => $scrap_wt,
                        'material_price' => $product->material_price,
                        'gst_percent_rate' => $product->gst_percent_rate,
                        'remark' => $remark,
                    ]);

                //   $this->calculateAndUpdateStock(
                //         $tenantId,
                //         $activeCompanyId, 
                //         $productData['product_id'],
                //         in_array($data['transaction_type'], ['s_inward', 's_outward', 's_adjustment']) ? $data['party_id'] : null,
                //         $financialYear->id
                //     ); 
                }
            }

    
            DB::commit();

            $vouchercreated = Voucher::with('party','Vouchermeta.category', 'Vouchermeta.Item')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->where('id',$voucher->id)
            ->first();
    
            return response()->json([
                'status' => 200,
                'message' => 'Voucher updated successfully',
                'voucher' =>  $vouchercreated,
            ]);
    
        } catch (\Exception $e) {
            DB::rollBack();
    
            return response()->json([
                'status' => 500,
                'message' => 'Failed to update voucher: ' . $e->getMessage(),
            ], 500);
        }
    }
    
    
    public function destroy($id, Request $request)
    {
        $response = $this->checkPermission('Voucher-Delete');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();
    
        $voucher = Voucher::with('Vouchermeta.category', 'Vouchermeta.Item')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->where('id', $id)
            ->first();
    
        if (!$voucher) {
            return response()->json([
                'status' => 200,
                'message' => 'voucher not found',
            ], 200);
        }
    
        try {
            DB::beginTransaction();
    
            // Get affected product IDs and party_id (if applicable)
            $affectedProductIds = $voucher->Vouchermeta->pluck('product_id')->toArray();
            $partyId = $voucher->party_id;

            // Financial year ID for stock recalculation
            $financialYear = $this->getFinancialYear(Carbon::parse($voucher->transaction_date));

            // Delete voucher and its metadata
            $voucher->Vouchermeta()->delete();
            $voucher->delete();

            // Recalculate stock for the affected products
            // foreach ($affectedProductIds as $productId) {
            //     $this->calculateAndUpdateStock(
            //         $tenantId,
            //         $activeCompanyId,
            //         $productId,
            //         in_array($voucher->transaction_type, ['s_inward', 's_outward', 's_adjustment']) ? $partyId : null,
            //         $financialYear->id
            //     );
            // }
            DB::commit();
    
            return response()->json([
                'status' => 200,
                'message' => 'Voucher deleted successfully',
            ]);
    
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
            
            return response()->json([
                'status' => 500,
                'message' => 'Failed to delete voucher: ' . $e->getMessage(),
            ], 500);
        }
    }


    public function checkVoucherNumber(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $companyId = $user->getActiveCompanyId();

        $data = $request->validate([
            'voucher_no' => [
                Rule::when(
                    function ($input) {
                        return !in_array($input['transaction_type'], ['adjustment', 's_adjustment']);
                    },
                    ['required'],
                    ['nullable']
                ),
            ],
            'transaction_date' => [
                Rule::when(
                    function ($input) {
                        return !in_array($input['transaction_type'], ['adjustment', 's_adjustment']);
                    },
                    ['required', 'date'],
                    ['nullable', 'date']
                ),
            ],
            'transaction_type' => 'required|in:inward,outward,adjustment,s_inward,s_outward,s_adjustment',
            'party_id' => 'required|exists:party,id',
        ]);

        // Skip voucher number check for adjustment and s_adjustment
        if (in_array($data['transaction_type'], ['adjustment', 's_adjustment'])) {
            return response()->json([
                'status' => 200,
                'message' => 'Adjustment voucher - no voucher number check required.',
                'voucher' => 0,
            ]);
        }
        $transactionDate = Carbon::parse($data['transaction_date']);

        $financialYear = $this->getFinancialYear($transactionDate);

       


        try {
            // Check for existing voucher
            $existingVoucher = Voucher::where('tenant_id', $tenantId)
                ->where('financial_year_id', $financialYear->id)
                ->where('transaction_type', $data['transaction_type'])
                ->where('party_id', $data['party_id'])
                ->where('voucher_no', $data['voucher_no'])
                ->first();

            if ($existingVoucher) {
                $existingCompanyName = company::where('id', $existingVoucher->company_id)->value('company_name');
                if ($existingVoucher->company_id === $companyId) {
                    return response()->json([
                        'status' => 200,
                        'message' => "Voucher number already exists for this company: {$existingCompanyName}.",
                        'voucher' => 1,
                    ]);
                } else {
                    return response()->json([
                        'status' => 200,
                        'message' => "Voucher number already exists for another company: {$existingCompanyName}.",
                        'voucher' => 1,
                    ]);
                }
            }

            // Voucher number does not exist
            return response()->json([
                'status' => 200,
                'message' => 'Voucher number is available.',
                'voucher' => 0,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to check voucher number: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function calculateAndUpdateStock($tenantId, $companyId, $productId, $partyId, $financialYearId)
    {
        try {
            // Get current financial year and all future years
            $currentFinancialYear = FinancialYear::findOrFail($financialYearId);

             // Also get the previous financial year
            $previousFinancialYear = FinancialYear::where('priority', '<', $currentFinancialYear->priority)
            ->orderBy('priority', 'desc')
            ->first();

            $affectedFinancialYears = FinancialYear::where('priority', '>=', $currentFinancialYear->priority)
                ->orderBy('priority')
                ->get();
    
            // Calculate in-house stock
            $this->updateInHouseStock($tenantId, $companyId, $productId, $affectedFinancialYears, $previousFinancialYear);
    
            // If it's a sub-vendor transaction, calculate sub-vendor stock
            if ($partyId) {
                $this->updateSubVendorStock($tenantId, $companyId, $productId, $partyId, $affectedFinancialYears, $previousFinancialYear);
            }
    
        } catch (\Exception $e) {
            throw new \Exception('Stock calculation failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Update in-house stock quantities
     */
    private function updateInHouseStock($tenantId, $companyId, $productId, $financialYears, $previousFinancialYear)
    {
        // Get or create in-house stock record
        $inHouseStock = ProductStock::firstOrNew([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'product_id' => $productId,
            'party_id' => null  // null party_id indicates in-house stock
        ]);
    
        $stockQuantities = json_decode($inHouseStock->stock_quantity, true) ?? [];
        $previousBalance = 0;

        
        // Get previous year's closing balance
        $previousBalance = 0;
        if ($previousFinancialYear) {
            $previousYearStock = $stockQuantities[$previousFinancialYear->id] ?? 0;
            $previousBalance = $previousYearStock;
        }
    
        foreach ($financialYears as $financialYear) {
            // Calculate stock movements for this year
            $movements = DB::table('voucher_meta')
                ->join('voucher', 'voucher_meta.voucher_id', '=', 'voucher.id')
                ->where('voucher.tenant_id', $tenantId)
                ->where('voucher.company_id', $companyId)
                ->where('voucher_meta.product_id', $productId)
                ->where('voucher.financial_year_id', $financialYear->id);
    
            // Calculate inward (including both regular and sub-vendor returns)
            $inward = (clone $movements)
                ->whereIn('voucher.transaction_type', ['inward', 'adjustment', 's_inward'])
                ->sum('voucher_meta.product_quantity');
    
            // Calculate outward (including both regular and sub-vendor dispatches)
            $outward = (clone $movements)
                ->whereIn('voucher.transaction_type', ['outward', 's_outward'])
                ->sum('voucher_meta.product_quantity');
    
            // Calculate current year's net movement
            $netMovement = $inward - $outward;
    
            // Calculate closing balance for this year
            $closingBalance = $previousBalance + $netMovement;
            $stockQuantities[$financialYear->id] = $closingBalance;
    
            // Set this year's closing balance as next year's opening balance
            $previousBalance = $closingBalance;
        }
    
        $inHouseStock->stock_quantity = json_encode($stockQuantities);
        $inHouseStock->save();
    }
    
    /**
     * Update sub-vendor specific stock quantities
     */
    private function updateSubVendorStock($tenantId, $companyId, $productId, $partyId, $financialYears, $previousFinancialYear)
    {
        // Get or create sub-vendor stock record
        $subVendorStock = ProductStock::firstOrNew([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'product_id' => $productId,
            'party_id' => $partyId
        ]);
    
        $stockQuantities = json_decode($subVendorStock->stock_quantity, true) ?? [];
        $previousBalance = 0;

        if ($previousFinancialYear) {
            $previousYearStock = $stockQuantities[$previousFinancialYear->id] ?? 0;
            $previousBalance = $previousYearStock;
        }
    
        foreach ($financialYears as $financialYear) {
            // Calculate sub-vendor specific movements
            $movements = DB::table('voucher_meta')
                ->join('voucher', 'voucher_meta.voucher_id', '=', 'voucher.id')
                ->where('voucher.tenant_id', $tenantId)
                ->where('voucher.company_id', $companyId)
                ->where('voucher_meta.product_id', $productId)
                ->where('voucher.party_id', $partyId)
                ->where('voucher.financial_year_id', $financialYear->id);
    
            // Calculate materials sent to sub-vendor
            $materialsReceived = (clone $movements)
                ->whereIn('voucher.transaction_type', ['s_outward'])
                ->sum('voucher_meta.product_quantity');
    
            // Calculate materials returned by sub-vendor
            $materialsReturned = (clone $movements)
                ->whereIn('voucher.transaction_type', ['s_inward'])
                ->sum('voucher_meta.product_quantity');
    
            // Calculate adjustments
            $adjustments = (clone $movements)
                ->where('voucher.transaction_type', 's_adjustment')
                ->sum('voucher_meta.product_quantity');
    
            // Calculate net movement for the year
            $netMovement = $materialsReceived - $materialsReturned + $adjustments;
    
            // Calculate closing balance for this year
            $closingBalance = $previousBalance + $netMovement;
            $stockQuantities[$financialYear->id] = $closingBalance;
    
            // Set this year's closing balance as next year's opening balance
            $previousBalance = $closingBalance;
        }
    
        $subVendorStock->stock_quantity = json_encode($stockQuantities);
        $subVendorStock->save();
    }


    public function generatePdf($id, Request $request)
    {
        $response = $this->checkPermission('Voucher-Show');
    
        if ($response) {
            return $response;
        }
    
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        $userSetting = usersettings::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->get();

        //dd($userSetting);
    
        $voucher = Voucher::with('company.state', 'party.state', 'Vouchermeta.category', 'Vouchermeta.Item')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->where('id', $id)
            ->first();
    
        if (!$voucher) {
            return response()->json([
                'status' => 200,
                'message' => 'Voucher not found',
            ], 200);
        }
    
        // Initialize DomPDF options
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
    
        if ($voucher->transaction_type == 'inward' || $voucher->transaction_type == 's_inward') {
            // Prepare the view with required data
            $view = view('pdf.voucherpdf', [
                'voucher' => $voucher,
                'userSetting' => $userSetting
            ])->render();
        } else {
             // Prepare the view with required data
            $view = view('pdf.voucherpdfout', [
                'voucher' => $voucher,
                'userSetting' => $userSetting
            ])->render();
        }    
        
        // Initialize Dompdf
        $pdf = new Dompdf($options);
    
        // Load HTML content
        $pdf->loadHtml($view, 'UTF-8');
    
        // Set paper size and orientation
        $pdf->setPaper('A5', 'portrait');
    
        // Render the PDF
        $pdf->render();
    
        //dd($voucher);
        // Add page numbers
        // $canvas = $pdf->getCanvas();
        // $footerText = '{PAGE_NUM}/{PAGE_COUNT}';
        // $font = $pdf->getFontMetrics()->get_font("Arial", "normal");
        // $fontSize = 10;
        // $x = $canvas->get_width() - 60; // Adjust X-coordinate for right alignment
        // $y = $canvas->get_height() - 30; // Adjust Y-coordinate for footer
    
        // $canvas->page_text($x, $y, $footerText, $font, $fontSize, [0, 0, 0]);
    
        // Stream the PDF to the browser
        //return $pdf->stream("voucher_invoice_{$voucher->id}.pdf");
        // Output PDF content
        $output = $pdf->output();

      
        // Return response with correct headers
        return response($output, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="voucher_invoice_' . $voucher->id . '.pdf"');
    }

    public function getPdfpreview($id, Request $request)
    {
        $response = $this->checkPermission('Voucher-Show');
    
        if ($response) {
            return $response;
        }
    
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        //     $userSetting = usersettings::where('tenant_id', $tenantId)
        //     ->where('user_id', $user->id)
        //     ->get();

        //    dd($userSetting);
    
        $voucher = Voucher::with('company.state', 'party.state', 'Vouchermeta.category', 'Vouchermeta.Item')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->where('id', $id)
            ->first();
    
        if (!$voucher) {
            return response()->json([
                'status' => 200,
                'message' => 'Voucher not found',
            ], 200);
        }
    
        
        return response()->json([
            'status' => 200,
            'message' => 'Voucher details retrieved successfully',
            'voucher' => $voucher,
        ]);
    }


    public function updateVoucherMetaByItem(Request $request)
    {
        $response = $this->checkPermission('Voucher-Update');
    
        if ($response) {
            return $response;
        }
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        // Validate the request data
        $data = $request->validate([
            'item_id' => 'required|exists:item,id',
            'job_work_rate' => 'sometimes|nullable|numeric',
            'scrap_wt' => 'sometimes|nullable|numeric',
        ]);

        // Check if item exists
        $item = Item::where('id', $data['item_id'])
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$item) {
            return response()->json([
                'status' => 404,
                'message' => 'Item not found',
            ], 404);
        }

        // Check if at least one field to update was provided
        if (!isset($data['job_work_rate']) && !isset($data['scrap_wt'])) {
            return response()->json([
                'status' => 400,
                'message' => 'At least one field (job_work_rate or scrap_wt) must be provided for update',
            ], 400);
        }

        try {
            DB::beginTransaction();

            // Get all voucher meta records for the specified item_id
            $voucherMetaRecords = Vouchermeta::where('item_id', $data['item_id'])->get();
            
            $updatedCount = 0;
            
            // Update each voucher meta record individually
            foreach ($voucherMetaRecords as $voucherMeta) {
                $updated = false;
                
                if (isset($data['job_work_rate'])) {
                    $voucherMeta->job_work_rate = $data['job_work_rate'];
                    $updated = true;
                }
                
                if (isset($data['scrap_wt'])) {
                    $voucherMeta->scrap_wt = $data['scrap_wt'];
                    $updated = true;
                }
                
                if ($updated) {
                    $voucherMeta->updated_at = now();
                    $voucherMeta->save();
                    $updatedCount++;
                }
            }

            DB::commit();

            return response()->json([
                'status' => 200,
                'message' => 'Voucher meta records updated successfully',
                'updated_count' => $updatedCount,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 500,
                'message' => 'Failed to update voucher meta records: ' . $e->getMessage(),
            ], 500);
        }
    }

    

}

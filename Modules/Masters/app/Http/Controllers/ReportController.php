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
use App\Models\party;
use App\Models\company;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class ReportController extends Controller
{
    public function stockreportindex(Request $request)
    {
        $response = $this->checkPermission('Stock-Reports-Menu');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }
    
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();
    
        // Get per_page parameter or default to 10
        $perPage = $request->input('per_page', 10);
        // Get page parameter or default to 1
        $page = $request->input('page', 1);
        $search = $request->input('search');
        
        // Fetch filter parameters
        $partyId = $request->input('party_id');
        $transactionType = $request->input('transaction_type');
        $itemId = $request->input('item_id');
        $categoryId = $request->input('category_id');
        $voucherId = $request->input('voucher_id');
        $transactionDateFrom = $request->input('transaction_date_from');
        $transactionDateTo = $request->input('transaction_date_to');
    
        // Start with the VoucherMeta query to get all meta records
        $query = VoucherMeta::with(['voucher.party', 'category', 'item'])
            ->whereHas('voucher', function ($q) use ($tenantId, $activeCompanyId) {
                $q->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId);
            })
            ->join('voucher', 'voucher_meta.voucher_id', '=', 'voucher.id')
            ->orderBy('voucher.transaction_date', 'desc')
            ->select('voucher_meta.*');
    
        // Apply search
        if ($search) {
            $query->whereHas('voucher', function ($q) use ($search) {
                $q->where('voucher_no', 'like', "%{$search}%")
                ->orWhere('vehicle_number', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%");
            });
        }
    
        // Apply filters
        if ($partyId) {
            $query->whereHas('voucher', function ($q) use ($partyId) {
                $q->where('party_id', $partyId);
            });
        }
    
        if ($transactionType) {
            $query->whereHas('voucher', function ($q) use ($transactionType) {
                $q->where('transaction_type', $transactionType);
            });
        }
    
        if ($itemId) {
            $query->where('item_id', $itemId);
        }
    
        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }
    
        if ($voucherId) {
            $query->where('voucher_id', $voucherId);
        }
    
        if ($transactionDateFrom && $transactionDateTo) {
            $query->whereHas('voucher', function ($q) use ($transactionDateFrom, $transactionDateTo) {
                $q->whereBetween('transaction_date', [$transactionDateFrom, $transactionDateTo]);
            });
        } elseif ($transactionDateFrom) {
            $query->whereHas('voucher', function ($q) use ($transactionDateFrom) {
                $q->where('transaction_date', '>=', $transactionDateFrom);
            });
        } elseif ($transactionDateTo) {
            $query->whereHas('voucher', function ($q) use ($transactionDateTo) {
                $q->where('transaction_date', '<=', $transactionDateTo);
            });
        }
    
        // Execute paginated query with explicitly setting the page
        $voucherMetas = $query->paginate($perPage, ['*'], 'page', $page);
    
        // Prepare flattened data structure
        $flattenedRecords = $voucherMetas->map(function ($voucherMeta) {
            $voucher = $voucherMeta->voucher;
            
            return [
                // VoucherMeta details
                'id' => $voucherMeta->id,
                'voucher_id' => $voucherMeta->voucher_id,
                'item_id' => $voucherMeta->item_id,
                'category_id' => $voucherMeta->category_id,
                'quantity' => $voucherMeta->item_quantity,
                'job_work_rate' => $voucherMeta->job_work_rate,
                'remark' =>$voucherMeta->remark,
               
                
                // Voucher details
                'voucher_no' => $voucher->voucher_no,
                'transaction_type' => $voucher->transaction_type,
                'transaction_date' => $voucher->transaction_date,
                'vehicle_number' => $voucher->vehicle_number,
                'description' => $voucher->description,
               
                
                // Party details
                'party_id' => $voucher->party_id,
                'party_name' => $voucher->party ? $voucher->party->name : null,
                'party_contact' => $voucher->party ? $voucher->party->contact : null,
                
                // Item details
                'item_name' => $voucherMeta->item ? $voucherMeta->item->name : null,
                'item_code' => $voucherMeta->item ? $voucherMeta->item->code : null,
                
                // Category details
                'category_name' => $voucherMeta->category ? $voucherMeta->category->name : null,
            ];
        });
    
        // Return response with pagination metadata
        return response()->json([
            'status' => 200,
            'message' => 'stock records retrieved successfully',
            'records' => $flattenedRecords, // Flattened records
            'pagination' => [
                'current_page' => $voucherMetas->currentPage(),
                'total_count' => $voucherMetas->total(),
                'per_page' => $voucherMetas->perPage(),
                'last_page' => $voucherMetas->lastPage(),
            ],
        ]);
    }

  
    public function getStockBalanceReport(Request $request)
    {
        $response = $this->checkPermission('Stock-Report-Download');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();
        
        // Get request parameters
        $partyId = $request->input('party_id');
        $itemId = $request->input('item_id');
        $transactionDateFrom = $request->input('transaction_date_from');
        $transactionDateTo = $request->input('transaction_date_to');
        
        // Validate required parameters
        if (!$itemId) {
            return response()->json([
                'status' => 422,
                'message' => 'Item ID is required',
            ], 200);
        }
        
        // If dates are not provided, use default range (e.g., current financial year)
        if (!$transactionDateFrom || !$transactionDateTo) {
            // Determine current financial year dates
            $currentMonth = date('m');
            $currentYear = date('Y');
            
            if ($currentMonth < 4) {
                // Current FY is previous year April to current year March
                $transactionDateFrom = ($currentYear - 1) . '-04-01';
                $transactionDateTo = $currentYear . '-03-31';
            } else {
                // Current FY is current year April to next year March
                $transactionDateFrom = $currentYear . '-04-01';
                $transactionDateTo = ($currentYear + 1) . '-03-31';
            }
        }
        
        // 1. Get active company details
        $company = company::with('state')->where('id', $activeCompanyId)
                        ->where('tenant_id', $tenantId)
                        ->first();
        
        if (!$company) {
            return response()->json([
                'status' => 422,
                'message' => 'Company not found',
            ], 200);
        }
        
        // 2. Get party details if party_id is provided
        $party = null;
        if ($partyId) {
            $party = party::with('state')->where('id', $partyId)
                        ->where('tenant_id', $tenantId)
                        ->where('company_id', $activeCompanyId)
                        ->first();
            
            if (!$party) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Party not found',
                ], 200);
            }
        }
        
        // 3. Get item details (parent item)
        $parentItem = Item::where('id', $itemId)
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $activeCompanyId)
                    ->when($partyId, function ($query) use ($partyId) {
                        return $query->whereJsonContains('party_id', (int) $partyId);
                    })
                    ->first();
        
        if (!$parentItem) {
            return response()->json([
                'status' => 422,
                'message' => 'Item not found',
            ], 200);
        }
        
        // 3.1 Get all child items with the parent item id
        $childItems = Item::where('parent_id', $itemId)
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $activeCompanyId)
                    ->when($partyId, function ($query) use ($partyId) {
                        return $query->whereJsonContains('party_id', (int) $partyId);
                    })
                    ->get();
        
        // Create array of all item IDs (parent + children)
        $allItemIds = [$itemId];
        foreach ($childItems as $childItem) {
            $allItemIds[] = $childItem->id;
        }
        
        // 4. Calculate opening balance (transactions before start date)
        $openingBalance = DB::table('voucher as v')
        ->join('voucher_meta as vm', 'v.id', '=', 'vm.voucher_id')
        ->where('v.tenant_id', $tenantId)
        ->where('v.company_id', $activeCompanyId)
        ->whereIn('vm.item_id', $allItemIds)
        ->where('v.transaction_date', '<', $transactionDateFrom)
        ->when($partyId, function ($query) use ($partyId) {
            return $query->where('v.party_id', $partyId);
        })
        ->selectRaw('COALESCE(SUM(
            CASE 
                WHEN v.transaction_type IN ("inward", "s_inward", "adjustment", "s_adjustment") 
                    THEN vm.item_quantity 
                ELSE -vm.item_quantity 
            END
        ), 0) as opening_balance')
        ->first();

        // 4.1 Get adjustment transactions within date range to add to opening balance
        $adjustmentsInDateRange = DB::table('voucher as v')
            ->join('voucher_meta as vm', 'v.id', '=', 'vm.voucher_id')
            ->where('v.tenant_id', $tenantId)
            ->where('v.company_id', $activeCompanyId)
            ->whereIn('vm.item_id', $allItemIds)
            ->whereBetween('v.transaction_date', [$transactionDateFrom, $transactionDateTo])
            ->whereIn('v.transaction_type', ['adjustment', 's_adjustment'])
            ->when($partyId, function ($query) use ($partyId) {
                return $query->where('v.party_id', $partyId);
            })
            ->selectRaw('COALESCE(SUM(vm.item_quantity), 0) as adjustment_total')
            ->first();
        
        // Adjust opening balance to include adjustments from the selected date range
        $adjustedOpeningBalance = $openingBalance->opening_balance + $adjustmentsInDateRange->adjustment_total;
        
    
        // 5. Get all transactions within date range, excluding adjustments that were added to opening balance
        $allTransactions = DB::table('voucher as v')
            ->join('voucher_meta as vm', 'v.id', '=', 'vm.voucher_id')
            ->leftJoin('party as p', 'v.party_id', '=', 'p.id')
            ->leftJoin('item_category as c', 'vm.category_id', '=', 'c.id')
            ->leftJoin('item as i', 'vm.item_id', '=', 'i.id')
            ->where('v.tenant_id', $tenantId)
            ->where('v.company_id', $activeCompanyId)
            ->whereIn('vm.item_id', $allItemIds)
            ->whereBetween('v.transaction_date', [$transactionDateFrom, $transactionDateTo])
            ->when($partyId, function ($query) use ($partyId) {
                return $query->where('v.party_id', $partyId);
            })
            ->select([
                'v.id as voucher_id',
                'v.voucher_no',
                'v.transaction_date',
                'v.transaction_type',
                'vm.item_id',
                'i.name as item_name',
                'i.parent_id',
                'vm.item_quantity',
                'vm.job_work_rate',
                'vm.remark',
                'v.vehicle_number',
                'v.description',
                'p.name as party_name',
                'c.name as category_name',
                DB::raw('CASE 
                    WHEN v.transaction_type IN ("inward", "s_inward") 
                        THEN vm.item_quantity 
                    ELSE 0 
                END as inward_quantity'),
                DB::raw('CASE 
                    WHEN v.transaction_type IN ("outward", "s_outward") 
                        THEN vm.item_quantity 
                    ELSE 0 
                END as outward_quantity')
            ])
            ->orderBy('v.transaction_date')
            ->orderBy('v.id')
            ->get();
            
        // 6. Filter out adjustment transactions from the transactions list
        // (since they're already accounted for in the opening balance)
        $visibleTransactions = $allTransactions->filter(function($transaction) {
            return !in_array($transaction->transaction_type, ['adjustment', 's_adjustment']);
        });
        
        // 7. Filter out adjustment transactions from the detailed view
        $visibleTransactions = $allTransactions->filter(function($transaction) {
            return !in_array($transaction->transaction_type, ['adjustment', 's_adjustment']);
        });
        
        // 8. Recalculate transaction details without adjustments
        $transactionDetails = [];
        $runningBalance = $adjustedOpeningBalance; // Start with adjusted opening balance
        $transaction_mr=0;
        $transaction_cr=0;
        $transaction_bh=0;
        $transaction_ok=0;
        $transaction_asitis=0;
        $transaction_mr_qty=0;
        $transaction_cr_qty=0;
        $transaction_bh_qty=0;
        $transaction_ok_qty=0;
        $transaction_asitis_qty=0;
        
        foreach ($visibleTransactions as $transaction) {
            // Check if this is a parent or child item
            $itemType = ($transaction->item_id == $itemId) ? 'parent' : 'child';

             // Count transactions that are outward/s_outward and have a remark
            if (in_array($transaction->transaction_type, ['outward', 's_inward']) && 
                !empty($transaction->remark)) {
                if ($transaction->remark === 'mr' ) {
                  
                    $transaction_mr++;
                    $transaction_mr_qty += $transaction->item_quantity;
                } elseif ($transaction->remark === 'cr' ) {
                    
                    $transaction_cr++;
                    $transaction_cr_qty += $transaction->item_quantity;
                } elseif ($transaction->remark === 'bh') {
                   
                    $transaction_bh++;
                    $transaction_bh_qty += $transaction->item_quantity;
                } elseif ($transaction->remark=== 'ok') {
                  
                    $transaction_ok++;
                    $transaction_ok_qty += $transaction->item_quantity;
                } elseif ($transaction->remark === 'as-it-is') {
                 
                    $transaction_asitis++;
                    $transaction_asitis_qty += $transaction->item_quantity;
                }
            }
            
            if (in_array($transaction->transaction_type, ['inward', 's_inward'])) {
                $runningBalance += $transaction->item_quantity;
            } else {
                $runningBalance -= $transaction->item_quantity;
            }
            
            $transactionDetails[] = [
                'voucher_id' => $transaction->voucher_id,
                'voucher_no' => $transaction->voucher_no,
                'transaction_date' => $transaction->transaction_date,
                'transaction_type' => $transaction->transaction_type,
                'party_name' => $transaction->party_name,
                'category_name' => $transaction->category_name,
                'item_id' => $transaction->item_id,
                'item_name' => $transaction->item_name,
                'item_type' => $itemType,
                'vehicle_number' => $transaction->vehicle_number,
                'description' => $transaction->description,
                'remark' => $transaction->remark,
                'job_work_rate' => $transaction->job_work_rate,
                'inward_quantity' => $transaction->inward_quantity,
                'outward_quantity' => $transaction->outward_quantity,
                'running_balance' => $runningBalance
            ];
        }
        
        $closingBalance = $runningBalance;
        
        // Calculate summary statistics (excluding adjustment transactions for accurate reporting)
        $totalInward = $visibleTransactions->sum('inward_quantity');
        $totalOutward = $visibleTransactions->sum('outward_quantity');
        
        // Prepare child items data for response
        $childItemsData = [];
        foreach ($childItems as $item) {
            $childItemsData[] = [
                'id' => $item->id,
                'name' => $item->name,
                'code' => $item->item_code,
                'hsn' => $item->hsn,
                'description' => $item->description,
            ];
        }
        
        // Return response
        return response()->json([
            'status' => 200,
            'message' => 'Stock balance report retrieved successfully',
            'data' => [
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
                'party' => $party ? [
                    'id' => $party->id,
                    'name' => $party->name,
                    'address_line_1' => $party->address1, // Fixed - was using company address
                    'address_line_2' => $party->address2, // Fixed - was using company address
                    'city' => $party->city, // Fixed - was using company address
                    'state' => $party->state->title, // Fixed - was using company address
                    'pincode' => $party->pincode, // Fixed - was using company address
                    'gst_number' => $party->gst_number, // Fixed - was using company address
                ] : null,
                'item' => [
                    'id' => $parentItem->id,
                    'name' => $parentItem->name,
                    'code' => $parentItem->item_code,
                    'hsn' => $parentItem->hsn,
                    'description' => $parentItem->description,
                ],
                'child_items' => $childItemsData,
                'date_range' => [
                    'from' => $transactionDateFrom,
                    'to' => $transactionDateTo,
                ],
                'summary' => [
                    'opening_balance' => (float) $adjustedOpeningBalance,
                    'total_inward' => (float) $totalInward,
                    'total_outward' => (float) $totalOutward,
                    'closing_balance' => (float) $closingBalance,
                    'transaction_count' => count($visibleTransactions),
                    'transaction_mr' => $transaction_mr,
                    'transaction_cr' => $transaction_cr,
                    'transaction_bh' => $transaction_bh,
                    'transaction_ok' => $transaction_ok,
                    'transaction_asitis' => $transaction_asitis,
                    'transaction_mr_qty' => $transaction_mr_qty,
                    'transaction_cr_qty' => $transaction_cr_qty,
                    'transaction_bh_qty' => $transaction_bh_qty,
                    'transaction_ok_qty' => $transaction_ok_qty,
                    'transaction_asitis_qty' => $transaction_asitis_qty

                ],
                'transactions' => $transactionDetails,
            ],
        ]);
    }

    public function downloadStockBalanceReport(Request $request)
    {
      $response = $this->checkPermission('Stock-Report-Download');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();
        
        // Get request parameters
        $partyId = $request->input('party_id');
        $itemId = $request->input('item_id');
        $transactionDateFrom = $request->input('transaction_date_from');
        $transactionDateTo = $request->input('transaction_date_to');
        
        // Validate required parameters
        if (!$itemId) {
            return response()->json([
                'status' => 422,
                'message' => 'Item ID is required',
            ], 200);
        }
        
        // If dates are not provided, use default range (e.g., current financial year)
        if (!$transactionDateFrom || !$transactionDateTo) {
            // Determine current financial year dates
            $currentMonth = date('m');
            $currentYear = date('Y');
            
            if ($currentMonth < 4) {
                // Current FY is previous year April to current year March
                $transactionDateFrom = ($currentYear - 1) . '-04-01';
                $transactionDateTo = $currentYear . '-03-31';
            } else {
                // Current FY is current year April to next year March
                $transactionDateFrom = $currentYear . '-04-01';
                $transactionDateTo = ($currentYear + 1) . '-03-31';
            }
        }
        
        // 1. Get active company details
        $company = company::with('state')->where('id', $activeCompanyId)
                        ->where('tenant_id', $tenantId)
                        ->first();
        
        if (!$company) {
            return response()->json([
                'status' => 422,
                'message' => 'Company not found',
            ], 200);
        }
        
        // 2. Get party details if party_id is provided
        $party = null;
        if ($partyId) {
            $party = party::with('state')->where('id', $partyId)
                        ->where('tenant_id', $tenantId)
                        ->where('company_id', $activeCompanyId)
                        ->first();
            
            if (!$party) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Party not found',
                ], 200);
            }
        }
        
        // 3. Get item details (parent item)
        $parentItem = Item::where('id', $itemId)
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $activeCompanyId)
                    ->when($partyId, function ($query) use ($partyId) {
                        return $query->whereJsonContains('party_id', (int) $partyId);
                    })
                    ->first();
        
        if (!$parentItem) {
            return response()->json([
                'status' => 422,
                'message' => 'Item not found',
            ], 200);
        }
        
        // 3.1 Get all child items with the parent item id
        $childItems = Item::where('parent_id', $itemId)
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $activeCompanyId)
                    ->when($partyId, function ($query) use ($partyId) {
                        return $query->whereJsonContains('party_id', (int) $partyId);
                    })
                    ->get();
        
        // Create array of all item IDs (parent + children)
        $allItemIds = [$itemId];
        foreach ($childItems as $childItem) {
            $allItemIds[] = $childItem->id;
        }
        
        // 4. Calculate opening balance (transactions before start date)
        $openingBalance = DB::table('voucher as v')
        ->join('voucher_meta as vm', 'v.id', '=', 'vm.voucher_id')
        ->where('v.tenant_id', $tenantId)
        ->where('v.company_id', $activeCompanyId)
        ->whereIn('vm.item_id', $allItemIds)
        ->where('v.transaction_date', '<', $transactionDateFrom)
        ->when($partyId, function ($query) use ($partyId) {
            return $query->where('v.party_id', $partyId);
        })
        ->selectRaw('COALESCE(SUM(
            CASE 
                WHEN v.transaction_type IN ("inward", "s_inward", "adjustment", "s_adjustment") 
                    THEN vm.item_quantity 
                ELSE -vm.item_quantity 
            END
        ), 0) as opening_balance')
        ->first();

        // 4.1 Get adjustment transactions within date range to add to opening balance
        $adjustmentsInDateRange = DB::table('voucher as v')
            ->join('voucher_meta as vm', 'v.id', '=', 'vm.voucher_id')
            ->where('v.tenant_id', $tenantId)
            ->where('v.company_id', $activeCompanyId)
            ->whereIn('vm.item_id', $allItemIds)
            ->whereBetween('v.transaction_date', [$transactionDateFrom, $transactionDateTo])
            ->whereIn('v.transaction_type', ['adjustment', 's_adjustment'])
            ->when($partyId, function ($query) use ($partyId) {
                return $query->where('v.party_id', $partyId);
            })
            ->selectRaw('COALESCE(SUM(vm.item_quantity), 0) as adjustment_total')
            ->first();
        
        // Adjust opening balance to include adjustments from the selected date range
        $adjustedOpeningBalance = $openingBalance->opening_balance + $adjustmentsInDateRange->adjustment_total;
        
    
        // 5. Get all transactions within date range, excluding adjustments that were added to opening balance
        $allTransactions = DB::table('voucher as v')
            ->join('voucher_meta as vm', 'v.id', '=', 'vm.voucher_id')
            ->leftJoin('party as p', 'v.party_id', '=', 'p.id')
            ->leftJoin('item_category as c', 'vm.category_id', '=', 'c.id')
            ->leftJoin('item as i', 'vm.item_id', '=', 'i.id')
            ->where('v.tenant_id', $tenantId)
            ->where('v.company_id', $activeCompanyId)
            ->whereIn('vm.item_id', $allItemIds)
            ->whereBetween('v.transaction_date', [$transactionDateFrom, $transactionDateTo])
            ->when($partyId, function ($query) use ($partyId) {
                return $query->where('v.party_id', $partyId);
            })
            ->select([
                'v.id as voucher_id',
                'v.voucher_no',
                'v.transaction_date',
                'v.transaction_type',
                'vm.item_id',
                'i.name as item_name',
                'i.parent_id',
                'vm.item_quantity',
                'vm.job_work_rate',
                'vm.remark',
                'v.vehicle_number',
                'v.description',
                'p.name as party_name',
                'c.name as category_name',
                DB::raw('CASE 
                    WHEN v.transaction_type IN ("inward", "s_inward") 
                        THEN vm.item_quantity 
                    ELSE 0 
                END as inward_quantity'),
                DB::raw('CASE 
                    WHEN v.transaction_type IN ("outward", "s_outward") 
                        THEN vm.item_quantity 
                    ELSE 0 
                END as outward_quantity')
            ])
            ->orderBy('v.transaction_date')
            ->orderBy('v.id')
            ->get();
            
        // 6. Filter out adjustment transactions from the transactions list
        // (since they're already accounted for in the opening balance)
        $visibleTransactions = $allTransactions->filter(function($transaction) {
            return !in_array($transaction->transaction_type, ['adjustment', 's_adjustment']);
        });
        
        // 7. Filter out adjustment transactions from the detailed view
        $visibleTransactions = $allTransactions->filter(function($transaction) {
            return !in_array($transaction->transaction_type, ['adjustment', 's_adjustment']);
        });
        
        // 8. Recalculate transaction details without adjustments
        $transactionDetails = [];
        $runningBalance = $adjustedOpeningBalance; // Start with adjusted opening balance
        $transaction_mr=0;
        $transaction_cr=0;
        $transaction_bh=0;
        $transaction_ok=0;
        $transaction_asitis=0;
        $transaction_mr_qty=0;
        $transaction_cr_qty=0;
        $transaction_bh_qty=0;
        $transaction_ok_qty=0;
        $transaction_asitis_qty=0;
        
        foreach ($visibleTransactions as $transaction) {
            // Check if this is a parent or child item
            $itemType = ($transaction->item_id == $itemId) ? 'parent' : 'child';

             // Count transactions that are outward/s_outward and have a remark
            if (in_array($transaction->transaction_type, ['outward', 's_inward']) && 
                !empty($transaction->remark)) {
                if ($transaction->remark === 'mr' ) {
                  
                    $transaction_mr++;
                    $transaction_mr_qty += $transaction->item_quantity;
                } elseif ($transaction->remark === 'cr' ) {
                    
                    $transaction_cr++;
                    $transaction_cr_qty += $transaction->item_quantity;
                } elseif ($transaction->remark === 'bh') {
                   
                    $transaction_bh++;
                    $transaction_bh_qty += $transaction->item_quantity;
                } elseif ($transaction->remark=== 'ok') {
                  
                    $transaction_ok++;
                    $transaction_ok_qty += $transaction->item_quantity;
                } elseif ($transaction->remark === 'as-it-is') {
                 
                    $transaction_asitis++;
                    $transaction_asitis_qty += $transaction->item_quantity;
                }
            }
            
            if (in_array($transaction->transaction_type, ['inward', 's_inward'])) {
                $runningBalance += $transaction->item_quantity;
            } else {
                $runningBalance -= $transaction->item_quantity;
            }
            
            $transactionDetails[] = [
                'voucher_id' => $transaction->voucher_id,
                'voucher_no' => $transaction->voucher_no,
                'transaction_date' => $transaction->transaction_date,
                'transaction_type' => $transaction->transaction_type,
                'party_name' => $transaction->party_name,
                'category_name' => $transaction->category_name,
                'item_id' => $transaction->item_id,
                'item_name' => $transaction->item_name,
                'item_type' => $itemType,
                'vehicle_number' => $transaction->vehicle_number,
                'description' => $transaction->description,
                'remark' => $transaction->remark,
                'job_work_rate' => $transaction->job_work_rate,
                'inward_quantity' => $transaction->inward_quantity,
                'outward_quantity' => $transaction->outward_quantity,
                'running_balance' => $runningBalance
            ];
        }
        
        $closingBalance = $runningBalance;
        
        // Calculate summary statistics (excluding adjustment transactions for accurate reporting)
        $totalInward = $visibleTransactions->sum('inward_quantity');
        $totalOutward = $visibleTransactions->sum('outward_quantity');
        
        // Prepare child items data for response
        $childItemsData = [];
        foreach ($childItems as $item) {
            $childItemsData[] = [
                'id' => $item->id,
                'name' => $item->name,
                'code' => $item->item_code,
                'hsn' => $item->hsn,
                'description' => $item->description,
            ];
        }
        
       
        $data =  [
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
                'party' => $party ? [
                    'id' => $party->id,
                    'name' => $party->name,
                    'address_line_1' => $party->address1, // Fixed - was using company address
                    'address_line_2' => $party->address2, // Fixed - was using company address
                    'city' => $party->city, // Fixed - was using company address
                    'state' => $party->state->title, // Fixed - was using company address
                    'pincode' => $party->pincode, // Fixed - was using company address
                    'gst_number' => $party->gst_number, // Fixed - was using company address
                ] : null,
                'item' => [
                    'id' => $parentItem->id,
                    'name' => $parentItem->name,
                    'code' => $parentItem->item_code,
                    'hsn' => $parentItem->hsn,
                    'description' => $parentItem->description,
                ],
                'child_items' => $childItemsData,
                'date_range' => [
                    'from' => $transactionDateFrom,
                    'to' => $transactionDateTo,
                ],
                'summary' => [
                    'opening_balance' => (float) $adjustedOpeningBalance,
                    'total_inward' => (float) $totalInward,
                    'total_outward' => (float) $totalOutward,
                    'closing_balance' => (float) $closingBalance,
                    'transaction_count' => count($visibleTransactions),
                    'transaction_mr' => $transaction_mr,
                    'transaction_cr' => $transaction_cr,
                    'transaction_bh' => $transaction_bh,
                    'transaction_ok' => $transaction_ok,
                    'transaction_asitis' => $transaction_asitis,
                    'transaction_mr_qty' => $transaction_mr_qty,
                    'transaction_cr_qty' => $transaction_cr_qty,
                    'transaction_bh_qty' => $transaction_bh_qty,
                    'transaction_ok_qty' => $transaction_ok_qty,
                    'transaction_asitis_qty' => $transaction_asitis_qty

                ],
                'transactions' => $transactionDetails,
            ];
       

        // Configure Dompdf
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans'); // Important for special characters

        // Instantiate Dompdf
        $pdf = new Dompdf($options);

        // Load the HTML from the Blade view
        $view = view('pdf.stocktransactions', ['data' => $data])->render();
        $pdf->loadHtml($view, 'UTF-8');

        // Set paper size and orientation
        $pdf->setPaper('A4', 'portrait');

        // Render the PDF
        $pdf->render();

        // Generate a dynamic filename
        $filename = "StockReport_" . ($data['item']['code'] ?? 'item') . "_" . date('Ymd') . ".pdf";
        
        // Stream the PDF to the browser (inline view)
        return $pdf->stream($filename);
    }


    public function getStockBalancesubcontractReport(Request $request)
    {
        $response = $this->checkPermission('Stock-Report-Download');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();
        
        // Get request parameters
        $partyId = $request->input('party_id');
        $itemId = $request->input('item_id');
        $transactionDateFrom = $request->input('transaction_date_from');
        $transactionDateTo = $request->input('transaction_date_to');
        
        // Validate required parameters
        if (!$itemId) {
            return response()->json([
                'status' => 422,
                'message' => 'Item ID is required',
            ], 200);
        }
        
        // If dates are not provided, use default range (e.g., current financial year)
        if (!$transactionDateFrom || !$transactionDateTo) {
            // Determine current financial year dates
            $currentMonth = date('m');
            $currentYear = date('Y');
            
            if ($currentMonth < 4) {
                // Current FY is previous year April to current year March
                $transactionDateFrom = ($currentYear - 1) . '-04-01';
                $transactionDateTo = $currentYear . '-03-31';
            } else {
                // Current FY is current year April to next year March
                $transactionDateFrom = $currentYear . '-04-01';
                $transactionDateTo = ($currentYear + 1) . '-03-31';
            }
        }
        
        // 1. Get active company details
        $company = company::with('state')->where('id', $activeCompanyId)
                        ->where('tenant_id', $tenantId)
                        ->first();
        
        if (!$company) {
            return response()->json([
                'status' => 422,
                'message' => 'Company not found',
            ], 200);
        }
        
        // 2. Get party details if party_id is provided
        $party = null;
        if ($partyId) {
            $party = party::with('state')->where('id', $partyId)
                        ->where('tenant_id', $tenantId)
                        ->where('company_id', $activeCompanyId)
                        ->first();
            
            if (!$party) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Party not found',
                ], 200);
            }
        }
        
        // 3. Get item details (parent item)
        $parentItem = Item::where('id', $itemId)
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $activeCompanyId)
                    ->when($partyId, function ($query) use ($partyId) {
                        return $query->whereJsonContains('party_id', (int) $partyId);
                    })
                    ->first();
        
        if (!$parentItem) {
            return response()->json([
                'status' => 422,
                'message' => 'Item not found',
            ], 200);
        }
        
        // 3.1 Get all child items with the parent item id
        $childItems = Item::where('parent_id', $itemId)
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $activeCompanyId)
                    ->when($partyId, function ($query) use ($partyId) {
                        return $query->whereJsonContains('party_id', (int) $partyId);
                    })
                    ->get();
        
        // Create array of all item IDs (parent + children)
        $allItemIds = [$itemId];
        foreach ($childItems as $childItem) {
            $allItemIds[] = $childItem->id;
        }
        
        // 4. Calculate opening balance (transactions before start date)
        $openingBalance = DB::table('voucher as v')
        ->join('voucher_meta as vm', 'v.id', '=', 'vm.voucher_id')
        ->where('v.tenant_id', $tenantId)
        ->where('v.company_id', $activeCompanyId)
        ->whereIn('vm.item_id', $allItemIds)
        ->where('v.transaction_date', '<', $transactionDateFrom)
        ->when($partyId, function ($query) use ($partyId) {
            return $query->where('v.party_id', $partyId);
        })
        ->selectRaw('COALESCE(SUM(
            CASE 
                WHEN v.transaction_type IN ("inward", "s_inward", "adjustment", "s_adjustment") 
                    THEN vm.item_quantity 
                ELSE -vm.item_quantity 
            END
        ), 0) as opening_balance')
        ->first();

        // 4.1 Get adjustment transactions within date range to add to opening balance
        $adjustmentsInDateRange = DB::table('voucher as v')
            ->join('voucher_meta as vm', 'v.id', '=', 'vm.voucher_id')
            ->where('v.tenant_id', $tenantId)
            ->where('v.company_id', $activeCompanyId)
            ->whereIn('vm.item_id', $allItemIds)
            ->whereBetween('v.transaction_date', [$transactionDateFrom, $transactionDateTo])
            ->whereIn('v.transaction_type', ['adjustment', 's_adjustment'])
            ->when($partyId, function ($query) use ($partyId) {
                return $query->where('v.party_id', $partyId);
            })
            ->selectRaw('COALESCE(SUM(vm.item_quantity), 0) as adjustment_total')
            ->first();
        
        // Adjust opening balance to include adjustments from the selected date range
        $adjustedOpeningBalance = $openingBalance->opening_balance + $adjustmentsInDateRange->adjustment_total;
        
    
        // 5. Get all transactions within date range, excluding adjustments that were added to opening balance
        $allTransactions = DB::table('voucher as v')
            ->join('voucher_meta as vm', 'v.id', '=', 'vm.voucher_id')
            ->leftJoin('party as p', 'v.party_id', '=', 'p.id')
            ->leftJoin('item_category as c', 'vm.category_id', '=', 'c.id')
            ->leftJoin('item as i', 'vm.item_id', '=', 'i.id')
            ->where('v.tenant_id', $tenantId)
            ->where('v.company_id', $activeCompanyId)
            ->whereIn('vm.item_id', $allItemIds)
            ->whereBetween('v.transaction_date', [$transactionDateFrom, $transactionDateTo])
            ->when($partyId, function ($query) use ($partyId) {
                return $query->where('v.party_id', $partyId);
            })
            ->select([
                'v.id as voucher_id',
                'v.voucher_no',
                'v.transaction_date',
                'v.transaction_type',
                'vm.item_id',
                'i.name as item_name',
                'i.parent_id',
                'vm.item_quantity',
                'vm.job_work_rate',
                'vm.remark',
                'v.vehicle_number',
                'v.description',
                'p.name as party_name',
                'c.name as category_name',
                DB::raw('CASE 
                    WHEN v.transaction_type IN ("inward", "s_inward") 
                        THEN vm.item_quantity 
                    ELSE 0 
                END as inward_quantity'),
                DB::raw('CASE 
                    WHEN v.transaction_type IN ("outward", "s_outward") 
                        THEN vm.item_quantity 
                    ELSE 0 
                END as outward_quantity')
            ])
            ->orderBy('v.transaction_date')
            ->orderBy('v.id')
            ->get();
            
        // 6. Filter out adjustment transactions from the transactions list
        // (since they're already accounted for in the opening balance)
        $visibleTransactions = $allTransactions->filter(function($transaction) {
            return !in_array($transaction->transaction_type, ['adjustment', 's_adjustment']);
        });
        
        // 7. Filter out adjustment transactions from the detailed view
        $visibleTransactions = $allTransactions->filter(function($transaction) {
            return !in_array($transaction->transaction_type, ['adjustment', 's_adjustment']);
        });
        
        // 8. Recalculate transaction details without adjustments
        $transactionDetails = [];
        $runningBalance = $adjustedOpeningBalance; // Start with adjusted opening balance
        $transaction_mr=0;
        $transaction_cr=0;
        $transaction_bh=0;
        $transaction_ok=0;
        $transaction_asitis=0;
        $transaction_mr_qty=0;
        $transaction_cr_qty=0;
        $transaction_bh_qty=0;
        $transaction_ok_qty=0;
        $transaction_asitis_qty=0;
        
        foreach ($visibleTransactions as $transaction) {
            // Check if this is a parent or child item
            $itemType = ($transaction->item_id == $itemId) ? 'parent' : 'child';

             // Count transactions that are outward/s_outward and have a remark
            if (in_array($transaction->transaction_type, ['outward', 's_inward']) && 
                !empty($transaction->remark)) {
                if ($transaction->remark === 'mr' ) {
                  
                    $transaction_mr++;
                    $transaction_mr_qty += $transaction->item_quantity;
                } elseif ($transaction->remark === 'cr' ) {
                    
                    $transaction_cr++;
                    $transaction_cr_qty += $transaction->item_quantity;
                } elseif ($transaction->remark === 'bh') {
                   
                    $transaction_bh++;
                    $transaction_bh_qty += $transaction->item_quantity;
                } elseif ($transaction->remark=== 'ok') {
                  
                    $transaction_ok++;
                    $transaction_ok_qty += $transaction->item_quantity;
                } elseif ($transaction->remark === 'as-it-is') {
                 
                    $transaction_asitis++;
                    $transaction_asitis_qty += $transaction->item_quantity;
                }
            }
            
            if ($transaction->transaction_type === 's_outward') {
                $runningBalance += $transaction->item_quantity;  // subcontractor receives
            } elseif ($transaction->transaction_type === 's_inward') {
                $runningBalance -= $transaction->item_quantity;  // subcontractor returns
            } elseif (in_array($transaction->transaction_type, ['inward'])) {
                $runningBalance += $transaction->item_quantity;  // vendor to contractor: normal inward
            } elseif (in_array($transaction->transaction_type, ['outward'])) {
                $runningBalance -= $transaction->item_quantity;  // contractor to vendor: normal outward
            }
            
            $transactionDetails[] = [
                'voucher_id' => $transaction->voucher_id,
                'voucher_no' => $transaction->voucher_no,
                'transaction_date' => $transaction->transaction_date,
                'transaction_type' => $transaction->transaction_type,
                'party_name' => $transaction->party_name,
                'category_name' => $transaction->category_name,
                'item_id' => $transaction->item_id,
                'item_name' => $transaction->item_name,
                'item_type' => $itemType,
                'vehicle_number' => $transaction->vehicle_number,
                'description' => $transaction->description,
                'remark' => $transaction->remark,
                'job_work_rate' => $transaction->job_work_rate,
                'inward_quantity' => $transaction->inward_quantity,
                'outward_quantity' => $transaction->outward_quantity,
                'running_balance' => $runningBalance
            ];
        }
        
        $closingBalance = $runningBalance;
        
        // Calculate summary statistics (excluding adjustment transactions for accurate reporting)
        $totalInward = $visibleTransactions->sum('inward_quantity');
        $totalOutward = $visibleTransactions->sum('outward_quantity');
        
        // Prepare child items data for response
        $childItemsData = [];
        foreach ($childItems as $item) {
            $childItemsData[] = [
                'id' => $item->id,
                'name' => $item->name,
                'code' => $item->item_code,
                'hsn' => $item->hsn,
                'description' => $item->description,
            ];
        }
        
        // Return response
        return response()->json([
            'status' => 200,
            'message' => 'Stock balance report retrieved successfully',
            'data' => [
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
                'party' => $party ? [
                    'id' => $party->id,
                    'name' => $party->name,
                    'address_line_1' => $party->address1, // Fixed - was using company address
                    'address_line_2' => $party->address2, // Fixed - was using company address
                    'city' => $party->city, // Fixed - was using company address
                    'state' => $party->state->title, // Fixed - was using company address
                    'pincode' => $party->pincode, // Fixed - was using company address
                    'gst_number' => $party->gst_number, // Fixed - was using company address
                ] : null,
                'item' => [
                    'id' => $parentItem->id,
                    'name' => $parentItem->name,
                    'code' => $parentItem->item_code,
                    'hsn' => $parentItem->hsn,
                    'description' => $parentItem->description,
                ],
                'child_items' => $childItemsData,
                'date_range' => [
                    'from' => $transactionDateFrom,
                    'to' => $transactionDateTo,
                ],
                'summary' => [
                    'opening_balance' => (float) $adjustedOpeningBalance,
                    'total_inward' => (float) $totalInward,
                    'total_outward' => (float) $totalOutward,
                    'closing_balance' => (float) $closingBalance,
                    'transaction_count' => count($visibleTransactions),
                    'transaction_mr' => $transaction_mr,
                    'transaction_cr' => $transaction_cr,
                    'transaction_bh' => $transaction_bh,
                    'transaction_ok' => $transaction_ok,
                    'transaction_asitis' => $transaction_asitis,
                    'transaction_mr_qty' => $transaction_mr_qty,
                    'transaction_cr_qty' => $transaction_cr_qty,
                    'transaction_bh_qty' => $transaction_bh_qty,
                    'transaction_ok_qty' => $transaction_ok_qty,
                    'transaction_asitis_qty' => $transaction_asitis_qty

                ],
                'transactions' => $transactionDetails,
            ],
        ]);
    }

    public function downloadStockBalancesubcontractReport(Request $request)
    {
        $response = $this->checkPermission('Stock-Report-Download');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();
        
        // Get request parameters
        $partyId = $request->input('party_id');
        $itemId = $request->input('item_id');
        $transactionDateFrom = $request->input('transaction_date_from');
        $transactionDateTo = $request->input('transaction_date_to');
        
        // Validate required parameters
        if (!$itemId) {
            return response()->json([
                'status' => 422,
                'message' => 'Item ID is required',
            ], 200);
        }
        
        // If dates are not provided, use default range (e.g., current financial year)
        if (!$transactionDateFrom || !$transactionDateTo) {
            // Determine current financial year dates
            $currentMonth = date('m');
            $currentYear = date('Y');
            
            if ($currentMonth < 4) {
                // Current FY is previous year April to current year March
                $transactionDateFrom = ($currentYear - 1) . '-04-01';
                $transactionDateTo = $currentYear . '-03-31';
            } else {
                // Current FY is current year April to next year March
                $transactionDateFrom = $currentYear . '-04-01';
                $transactionDateTo = ($currentYear + 1) . '-03-31';
            }
        }
        
        // 1. Get active company details
        $company = company::with('state')->where('id', $activeCompanyId)
                        ->where('tenant_id', $tenantId)
                        ->first();
        
        if (!$company) {
            return response()->json([
                'status' => 422,
                'message' => 'Company not found',
            ], 200);
        }
        
        // 2. Get party details if party_id is provided
        $party = null;
        if ($partyId) {
            $party = party::with('state')->where('id', $partyId)
                        ->where('tenant_id', $tenantId)
                        ->where('company_id', $activeCompanyId)
                        ->first();
            
            if (!$party) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Party not found',
                ], 200);
            }
        }
        
        // 3. Get item details (parent item)
        $parentItem = Item::where('id', $itemId)
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $activeCompanyId)
                    ->when($partyId, function ($query) use ($partyId) {
                        return $query->whereJsonContains('party_id', (int) $partyId);
                    })
                    ->first();
        
        if (!$parentItem) {
            return response()->json([
                'status' => 422,
                'message' => 'Item not found',
            ], 200);
        }
        
        // 3.1 Get all child items with the parent item id
        $childItems = Item::where('parent_id', $itemId)
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $activeCompanyId)
                    ->when($partyId, function ($query) use ($partyId) {
                        return $query->whereJsonContains('party_id', (int) $partyId);
                    })
                    ->get();
        
        // Create array of all item IDs (parent + children)
        $allItemIds = [$itemId];
        foreach ($childItems as $childItem) {
            $allItemIds[] = $childItem->id;
        }
        
        // 4. Calculate opening balance (transactions before start date)
        $openingBalance = DB::table('voucher as v')
        ->join('voucher_meta as vm', 'v.id', '=', 'vm.voucher_id')
        ->where('v.tenant_id', $tenantId)
        ->where('v.company_id', $activeCompanyId)
        ->whereIn('vm.item_id', $allItemIds)
        ->where('v.transaction_date', '<', $transactionDateFrom)
        ->when($partyId, function ($query) use ($partyId) {
            return $query->where('v.party_id', $partyId);
        })
        ->selectRaw('COALESCE(SUM(
            CASE 
                WHEN v.transaction_type IN ("inward", "s_inward", "adjustment", "s_adjustment") 
                    THEN vm.item_quantity 
                ELSE -vm.item_quantity 
            END
        ), 0) as opening_balance')
        ->first();

        // 4.1 Get adjustment transactions within date range to add to opening balance
        $adjustmentsInDateRange = DB::table('voucher as v')
            ->join('voucher_meta as vm', 'v.id', '=', 'vm.voucher_id')
            ->where('v.tenant_id', $tenantId)
            ->where('v.company_id', $activeCompanyId)
            ->whereIn('vm.item_id', $allItemIds)
            ->whereBetween('v.transaction_date', [$transactionDateFrom, $transactionDateTo])
            ->whereIn('v.transaction_type', ['adjustment', 's_adjustment'])
            ->when($partyId, function ($query) use ($partyId) {
                return $query->where('v.party_id', $partyId);
            })
            ->selectRaw('COALESCE(SUM(vm.item_quantity), 0) as adjustment_total')
            ->first();
        
        // Adjust opening balance to include adjustments from the selected date range
        $adjustedOpeningBalance = $openingBalance->opening_balance + $adjustmentsInDateRange->adjustment_total;
        
    
        // 5. Get all transactions within date range, excluding adjustments that were added to opening balance
        $allTransactions = DB::table('voucher as v')
            ->join('voucher_meta as vm', 'v.id', '=', 'vm.voucher_id')
            ->leftJoin('party as p', 'v.party_id', '=', 'p.id')
            ->leftJoin('item_category as c', 'vm.category_id', '=', 'c.id')
            ->leftJoin('item as i', 'vm.item_id', '=', 'i.id')
            ->where('v.tenant_id', $tenantId)
            ->where('v.company_id', $activeCompanyId)
            ->whereIn('vm.item_id', $allItemIds)
            ->whereBetween('v.transaction_date', [$transactionDateFrom, $transactionDateTo])
            ->when($partyId, function ($query) use ($partyId) {
                return $query->where('v.party_id', $partyId);
            })
            ->select([
                'v.id as voucher_id',
                'v.voucher_no',
                'v.transaction_date',
                'v.transaction_type',
                'vm.item_id',
                'i.name as item_name',
                'i.parent_id',
                'vm.item_quantity',
                'vm.job_work_rate',
                'vm.remark',
                'v.vehicle_number',
                'v.description',
                'p.name as party_name',
                'c.name as category_name',
                DB::raw('CASE 
                    WHEN v.transaction_type IN ("inward", "s_inward") 
                        THEN vm.item_quantity 
                    ELSE 0 
                END as inward_quantity'),
                DB::raw('CASE 
                    WHEN v.transaction_type IN ("outward", "s_outward") 
                        THEN vm.item_quantity 
                    ELSE 0 
                END as outward_quantity')
            ])
            ->orderBy('v.transaction_date')
            ->orderBy('v.id')
            ->get();
            
        // 6. Filter out adjustment transactions from the transactions list
        // (since they're already accounted for in the opening balance)
        $visibleTransactions = $allTransactions->filter(function($transaction) {
            return !in_array($transaction->transaction_type, ['adjustment', 's_adjustment']);
        });
        
        // 7. Filter out adjustment transactions from the detailed view
        $visibleTransactions = $allTransactions->filter(function($transaction) {
            return !in_array($transaction->transaction_type, ['adjustment', 's_adjustment']);
        });
        
        // 8. Recalculate transaction details without adjustments
        $transactionDetails = [];
        $runningBalance = $adjustedOpeningBalance; // Start with adjusted opening balance
        $transaction_mr=0;
        $transaction_cr=0;
        $transaction_bh=0;
        $transaction_ok=0;
        $transaction_asitis=0;
        $transaction_mr_qty=0;
        $transaction_cr_qty=0;
        $transaction_bh_qty=0;
        $transaction_ok_qty=0;
        $transaction_asitis_qty=0;
        
        foreach ($visibleTransactions as $transaction) {
            // Check if this is a parent or child item
            $itemType = ($transaction->item_id == $itemId) ? 'parent' : 'child';

             // Count transactions that are outward/s_outward and have a remark
            if (in_array($transaction->transaction_type, ['outward', 's_inward']) && 
                !empty($transaction->remark)) {
                if ($transaction->remark === 'mr' ) {
                  
                    $transaction_mr++;
                    $transaction_mr_qty += $transaction->item_quantity;
                } elseif ($transaction->remark === 'cr' ) {
                    
                    $transaction_cr++;
                    $transaction_cr_qty += $transaction->item_quantity;
                } elseif ($transaction->remark === 'bh') {
                   
                    $transaction_bh++;
                    $transaction_bh_qty += $transaction->item_quantity;
                } elseif ($transaction->remark=== 'ok') {
                  
                    $transaction_ok++;
                    $transaction_ok_qty += $transaction->item_quantity;
                } elseif ($transaction->remark === 'as-it-is') {
                 
                    $transaction_asitis++;
                    $transaction_asitis_qty += $transaction->item_quantity;
                }
            }
            
            if ($transaction->transaction_type === 's_outward') {
                $runningBalance += $transaction->item_quantity;  // subcontractor receives
            } elseif ($transaction->transaction_type === 's_inward') {
                $runningBalance -= $transaction->item_quantity;  // subcontractor returns
            } elseif (in_array($transaction->transaction_type, ['inward'])) {
                $runningBalance += $transaction->item_quantity;  // vendor to contractor: normal inward
            } elseif (in_array($transaction->transaction_type, ['outward'])) {
                $runningBalance -= $transaction->item_quantity;  // contractor to vendor: normal outward
            }
            
            $transactionDetails[] = [
                'voucher_id' => $transaction->voucher_id,
                'voucher_no' => $transaction->voucher_no,
                'transaction_date' => $transaction->transaction_date,
                'transaction_type' => $transaction->transaction_type,
                'party_name' => $transaction->party_name,
                'category_name' => $transaction->category_name,
                'item_id' => $transaction->item_id,
                'item_name' => $transaction->item_name,
                'item_type' => $itemType,
                'vehicle_number' => $transaction->vehicle_number,
                'description' => $transaction->description,
                'remark' => $transaction->remark,
                'job_work_rate' => $transaction->job_work_rate,
                'inward_quantity' => $transaction->inward_quantity,
                'outward_quantity' => $transaction->outward_quantity,
                'running_balance' => $runningBalance
            ];
        }
        
        $closingBalance = $runningBalance;
        
        // Calculate summary statistics (excluding adjustment transactions for accurate reporting)
        $totalInward = $visibleTransactions->sum('inward_quantity');
        $totalOutward = $visibleTransactions->sum('outward_quantity');
        
        // Prepare child items data for response
        $childItemsData = [];
        foreach ($childItems as $item) {
            $childItemsData[] = [
                'id' => $item->id,
                'name' => $item->name,
                'code' => $item->item_code,
                'hsn' => $item->hsn,
                'description' => $item->description,
            ];
        }
        
        // response
      
            $data = [
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
                'party' => $party ? [
                    'id' => $party->id,
                    'name' => $party->name,
                    'address_line_1' => $party->address1, // Fixed - was using company address
                    'address_line_2' => $party->address2, // Fixed - was using company address
                    'city' => $party->city, // Fixed - was using company address
                    'state' => $party->state->title, // Fixed - was using company address
                    'pincode' => $party->pincode, // Fixed - was using company address
                    'gst_number' => $party->gst_number, // Fixed - was using company address
                ] : null,
                'item' => [
                    'id' => $parentItem->id,
                    'name' => $parentItem->name,
                    'code' => $parentItem->item_code,
                    'hsn' => $parentItem->hsn,
                    'description' => $parentItem->description,
                ],
                'child_items' => $childItemsData,
                'date_range' => [
                    'from' => $transactionDateFrom,
                    'to' => $transactionDateTo,
                ],
                'summary' => [
                    'opening_balance' => (float) $adjustedOpeningBalance,
                    'total_inward' => (float) $totalInward,
                    'total_outward' => (float) $totalOutward,
                    'closing_balance' => (float) $closingBalance,
                    'transaction_count' => count($visibleTransactions),
                    'transaction_mr' => $transaction_mr,
                    'transaction_cr' => $transaction_cr,
                    'transaction_bh' => $transaction_bh,
                    'transaction_ok' => $transaction_ok,
                    'transaction_asitis' => $transaction_asitis,
                    'transaction_mr_qty' => $transaction_mr_qty,
                    'transaction_cr_qty' => $transaction_cr_qty,
                    'transaction_bh_qty' => $transaction_bh_qty,
                    'transaction_ok_qty' => $transaction_ok_qty,
                    'transaction_asitis_qty' => $transaction_asitis_qty

                ],
                'transactions' => $transactionDetails,
            ];

            // Configure Dompdf
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans'); // Important for special characters

        // Instantiate Dompdf
        $pdf = new Dompdf($options);

        // Load the HTML from the Blade view
        $view = view('pdf.stocktransactionsoutsource', ['data' => $data])->render();
        $pdf->loadHtml($view, 'UTF-8');

        // Set paper size and orientation
        $pdf->setPaper('A4', 'portrait');

        // Render the PDF
        $pdf->render();

        // Generate a dynamic filename
        $filename = "StockReportoutsource_" . ($data['item']['code'] ?? 'item') . "_" . date('Ymd') . ".pdf";
        
        // Stream the PDF to the browser (inline view)
        return $pdf->stream($filename); 
       
    }

    public function salesreportindex(Request $request)
    {
        $response = $this->checkPermission('Sales-Job-Work-Reports-Menu');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        // Get per_page parameter or default to 10
        $perPage = $request->input('per_page', 10);
        // Get page parameter or default to 1
        $page = $request->input('page', 1);
        $search = $request->input('search');
        
        // Fetch filter parameters
        $partyId = $request->input('party_id');
        $itemId = $request->input('item_id');
        $categoryId = $request->input('category_id');
        $voucherId = $request->input('voucher_id');
        $transactionDateFrom = $request->input('transaction_date_from');
        $transactionDateTo = $request->input('transaction_date_to');

    
       

        // Start with the VoucherMeta query to get all meta records
        $query = VoucherMeta::with(['voucher.party', 'category', 'item'])
            ->whereHas('voucher', function ($q) use ($tenantId, $activeCompanyId) {
                $q->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->where('transaction_type', 'outward'); // Filter for outward transactions only
            })
            ->join('voucher', 'voucher_meta.voucher_id', '=', 'voucher.id')
            ->orderBy('voucher.transaction_date', 'desc')
            ->select('voucher_meta.*');

        // Apply search
        if ($search) {
            $query->whereHas('voucher', function ($q) use ($search) {
                $q->where('voucher_no', 'like', "%{$search}%")
                ->orWhere('vehicle_number', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Apply filters
        if ($partyId) {
            $query->whereHas('voucher', function ($q) use ($partyId) {
                $q->where('party_id', $partyId);
            });
        }

        if ($itemId) {
            $query->where('item_id', $itemId);
        }

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        if ($voucherId) {
            $query->where('voucher_id', $voucherId);
        }

        if ($transactionDateFrom && $transactionDateTo) {
            $query->whereHas('voucher', function ($q) use ($transactionDateFrom, $transactionDateTo) {
                $q->whereBetween('transaction_date', [$transactionDateFrom, $transactionDateTo]);
            });
        } elseif ($transactionDateFrom) {
            $query->whereHas('voucher', function ($q) use ($transactionDateFrom) {
                $q->where('transaction_date', '>=', $transactionDateFrom);
            });
        } elseif ($transactionDateTo) {
            $query->whereHas('voucher', function ($q) use ($transactionDateTo) {
                $q->where('transaction_date', '<=', $transactionDateTo);
            });
        }

        // Execute paginated query with explicitly setting the page
        $voucherMetas = $query->paginate($perPage, ['*'], 'page', $page);

        $userSettings = usersettings::where('tenant_id', $tenantId)
        ->where('user_id', $user->id)
        ->whereIn('slug', ['jobwork_inhouse_cr', 'jobwork_inhouse_mr', 'jobwork_inhouse_bh','jobwork_show_gst'])
        ->pluck('val', 'slug')
        ->toArray();

        // Prepare flattened data structure
        $flattenedRecords = $voucherMetas->map(function ($voucherMeta) use ($userSettings){
          
        

            $voucher = $voucherMeta->voucher;
            $rate = 0;
            $quantity = $voucherMeta->item_quantity ?? 0; // Default to 0 if null
            $remark = strtolower($voucherMeta->remark ?? ''); // Normalize remark to lowercase
            $gstpercentage = $voucherMeta->gst_percent_rate;
            
            // Ensure user settings are available for each condition
            $jobwork_inhouse_cr = $userSettings['jobwork_inhouse_cr'] ?? 'no';
            $jobwork_inhouse_mr = $userSettings['jobwork_inhouse_mr'] ?? 'no';
            $jobwork_inhouse_bh = $userSettings['jobwork_inhouse_bh'] ?? 'no';
            $jobwork_show_gst = $userSettings['jobwork_show_gst'] ?? 'no';
            
            // Determine the rate based on remark and user settings
            if ($remark === 'mr' && $jobwork_inhouse_mr === 'yes') {
                $rate = $voucherMeta->job_work_rate ?? 0;  // Use default if null
            } elseif ($remark === 'cr' && $jobwork_inhouse_cr === 'yes') {
                $rate = $voucherMeta->job_work_rate ?? 0;
            } elseif ($remark === 'bh' && $jobwork_inhouse_bh === 'yes') {
                $rate = $voucherMeta->job_work_rate ?? 0;
            } elseif ($remark === 'ok') {
                $rate = $voucherMeta->job_work_rate ?? 0;
            } elseif ($remark === 'as-it-is') {
                $rate = 0;
            }
                
            $totalPrice = $rate * $quantity;

            // Add GST if applicable
            if ($jobwork_show_gst === 'yes' && $gstpercentage > 0) {
                $totalPrice += ($totalPrice * $gstpercentage) / 100;
            }
          
            return [
                // VoucherMeta details
                'id' => $voucherMeta->id,
                'voucher_id' => $voucherMeta->voucher_id,
                'item_id' => $voucherMeta->item_id,
                'category_id' => $voucherMeta->category_id,
                'quantity' => $voucherMeta->item_quantity,
                'job_work_rate' => $voucherMeta->job_work_rate,
                'scrap_wt' => $voucherMeta->scrap_wt,
                'material_price' => $voucherMeta->material_price,
                'gst_percent_rate' => $voucherMeta->gst_percent_rate,
                'remark' => $voucherMeta->remark,
                'Total_Price' => $totalPrice,

                // Voucher details
                'voucher_no' => $voucher->voucher_no,
                'transaction_date' => $voucher->transaction_date,
                'vehicle_number' => $voucher->vehicle_number,
                'description' => $voucher->description,
                
                // Party details
                'party_id' => $voucher->party_id,
                'party_name' => $voucher->party ? $voucher->party->name : null,
                
                
                // Item details
                'item_name' => $voucherMeta->item ? $voucherMeta->item->name : null,
                'item_code' => $voucherMeta->item ? $voucherMeta->item->item_code : null,
                
                // Category details
                'category_name' => $voucherMeta->category ? $voucherMeta->category->name : null,
            ];
        });

       

        // Return response with pagination metadata
        return response()->json([
            'status' => 200,
            'message' => 'Sales records retrieved successfully',
            'records' => $flattenedRecords, // Flattened records
            'pagination' => [
                'current_page' => $voucherMetas->currentPage(),
                'total_count' => $voucherMetas->total(),
                'per_page' => $voucherMetas->perPage(),
                'last_page' => $voucherMetas->lastPage(),
            ],
        ]);
    }

    public function getSalesReport(Request $request)
    {
        $response = $this->checkPermission('Sales-Job-Work-Reports-Download');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();
        
        // Get request parameters
        $partyId = $request->input('party_id');
        $itemId = $request->input('item_id');
        $transactionDateFrom = $request->input('transaction_date_from');
        $transactionDateTo = $request->input('transaction_date_to');
        
        // Validate required parameters
        if (!$itemId) {
            return response()->json([
                'status' => 422,
                'message' => 'Item ID is required',
            ], 200);
        }
        
        // If dates are not provided, use default range (e.g., current financial year)
        if (!$transactionDateFrom || !$transactionDateTo) {
            // Determine current financial year dates
            $currentMonth = date('m');
            $currentYear = date('Y');
            
            if ($currentMonth < 4) {
                // Current FY is previous year April to current year March
                $transactionDateFrom = ($currentYear - 1) . '-04-01';
                $transactionDateTo = $currentYear . '-03-31';
            } else {
                // Current FY is current year April to next year March
                $transactionDateFrom = $currentYear . '-04-01';
                $transactionDateTo = ($currentYear + 1) . '-03-31';
            }
        }
        
        // 1. Get active company details
        $company = Company::with('state')->where('id', $activeCompanyId)
                        ->where('tenant_id', $tenantId)
                        ->first();
        
        if (!$company) {
            return response()->json([
                'status' => 422,
                'message' => 'Company not found',
            ], 200);
        }
        
        // 2. Get party details if party_id is provided
        $party = null;
        if ($partyId) {
            $party = Party::with('state')->where('id', $partyId)
                        ->where('tenant_id', $tenantId)
                        ->where('company_id', $activeCompanyId)
                        ->first();
            
            if (!$party) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Party not found',
                ], 200);
            }
        }
        
        // 3. Get item details
        $item = Item::where('id', $itemId)
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $activeCompanyId)
                    ->when($partyId, function($query) use ($partyId) {
                        return $query->whereJsonContains('party_id', (int) $partyId);
                    })
                    ->first();
        
        if (!$item) {
            return response()->json([
                'status' => 422,
                'message' => 'Item not found',
            ], 200);
        }
        
        // 4. Get all relevant transactions (voucher meta entries)
       $query = VoucherMeta::with(['voucher.party', 'category', 'item'])
            ->whereHas('voucher', function ($q) use ($tenantId, $activeCompanyId, $transactionDateFrom, $transactionDateTo, $partyId) {
                $q->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->where('transaction_type', 'outward') // Sales are outward transactions
                ->whereBetween('transaction_date', [$transactionDateFrom, $transactionDateTo]);
                
                if ($partyId) {
                    $q->where('party_id', $partyId);
                }
            })
            ->where('item_id', $itemId)
            ->join('voucher', 'voucher_meta.voucher_id', '=', 'voucher.id')
            ->orderBy('voucher.transaction_date', 'asc')
            ->select('voucher_meta.*');
        
        $voucherMetas = $query->get();
        
        // 5. Calculate total sales and prepare transaction details
        $totalSales = 0;
        $transaction_mr=0;
        $transaction_cr=0;
        $transaction_bh=0;
        $transaction_ok=0;
        $transaction_asitis=0;
        $transaction_mr_qty=0;
        $transaction_cr_qty=0;
        $transaction_bh_qty=0;
        $transaction_ok_qty=0;
        $transaction_asitis_qty=0;

        $transactionDetails = [];
        $userSettings = usersettings::where('tenant_id', $tenantId)
        ->where('user_id', $user->id)
        ->whereIn('slug', ['jobwork_inhouse_cr', 'jobwork_inhouse_mr', 'jobwork_inhouse_bh','jobwork_show_gst','include_jobwork_rate_in_report'])
        ->pluck('val', 'slug')
        ->toArray();

         // Ensure user settings are available for each condition
         $jobwork_inhouse_cr = $userSettings['jobwork_inhouse_cr'] ?? 'no';
         $jobwork_inhouse_mr = $userSettings['jobwork_inhouse_mr'] ?? 'no';
         $jobwork_inhouse_bh = $userSettings['jobwork_inhouse_bh'] ?? 'no';
         $jobwork_show_gst = $userSettings['jobwork_show_gst'] ?? 'no';
         $include_jobwork_rate_in_report = $userSettings['include_jobwork_rate_in_report'] ?? 'no';
        
        foreach ($voucherMetas as $voucherMeta) {
            $voucher = $voucherMeta->voucher;

            $rate = 0;
            $quantity = $voucherMeta->item_quantity ?? 0; // Default to 0 if null
            $remark = strtolower($voucherMeta->remark ?? ''); // Normalize remark to lowercase
            $gstpercentage = $voucherMeta->gst_percent_rate;
            //Determine the rate based on remark and user settings
            if ($remark === 'mr' && $jobwork_inhouse_mr === 'yes') {
                $rate = $voucherMeta->job_work_rate ?? 0;  // Use default if null
                $transaction_mr++;
                $transaction_mr_qty += $quantity;
            } elseif ($remark === 'cr' && $jobwork_inhouse_cr === 'yes') {
                $rate = $voucherMeta->job_work_rate ?? 0;
                $transaction_cr++;
                $transaction_cr_qty += $quantity;
            } elseif ($remark === 'bh' && $jobwork_inhouse_bh === 'yes') {
                $rate = $voucherMeta->job_work_rate ?? 0;
                $transaction_bh++;
                $transaction_bh_qty += $quantity;
            } elseif ($remark === 'ok') {
                $rate = $voucherMeta->job_work_rate ?? 0;
                $transaction_ok++;
                $transaction_ok_qty += $quantity;
            } elseif ($remark === 'as-it-is') {
                $rate = 0;
                $transaction_asitis++;
                $transaction_asitis_qty += $quantity;
            }

            //  if ($remark === 'mr' ) {
            //     if($jobwork_inhouse_mr === 'yes'){
            //         $rate = $voucherMeta->job_work_rate ?? 0;  // Use default if null
            //     }
            //     $transaction_mr++;
            //     $transaction_mr_qty += $quantity;
            // } elseif ($remark === 'cr' ) {
            //     if($jobwork_inhouse_cr === 'yes'){
            //         $rate = $voucherMeta->job_work_rate ?? 0;
            //     }
            //     $transaction_cr++;
            //     $transaction_cr_qty += $quantity;
            // } elseif ($remark === 'bh' ) {
            //     if($jobwork_inhouse_bh === 'yes'){
            //         $rate = $voucherMeta->job_work_rate ?? 0;
            //     }
            //     $transaction_bh++;
            //     $transaction_bh_qty += $quantity;
            // } elseif ($remark === 'ok') {
            //         $rate = $voucherMeta->job_work_rate ?? 0;
            //     $transaction_ok++;
            //     $transaction_ok_qty += $quantity;
            // } elseif ($remark === 'as-it-is') {
            //     $rate = 0;
            //     $transaction_asitis++;
            //     $transaction_asitis_qty += $quantity;
            // }
                
            $totalPrice = $rate * $quantity;
            if ($jobwork_show_gst === 'yes' && $gstpercentage > 0) {
                $totalPrice += ($totalPrice * $gstpercentage) / 100;
            }
            $totalSales += $totalPrice;

             // Determine if transaction should be shown based on remark and user settings
            switch ($remark) {
                case 'mr':
                    $showTransaction = ($jobwork_inhouse_mr === 'yes');
                    break;
                case 'cr':
                    $showTransaction = ($jobwork_inhouse_cr === 'yes');
                    break;
                case 'bh':
                    $showTransaction = ($jobwork_inhouse_bh === 'yes');
                    break;
                case 'ok':
                    $showTransaction = true; // Always show OK transactions
                    break;
                case 'as-it-is':
                    $showTransaction = ($quantity > 0); // Show only if quantity exists
                    break;
                default:
                    $showTransaction = true; // For any other remarks, show by default
            }


            if( $showTransaction)
            {
                $transactionDetails[] = [
                    'id' => $voucherMeta->id,
                    'voucher_id' => $voucherMeta->voucher_id,
                    'voucher_no' => $voucher->voucher_no,
                    'transaction_date' => $voucher->transaction_date,
                    'party_name' => $voucher->party ? $voucher->party->name : null,
                    'item_name' => $voucherMeta->item ? $voucherMeta->item->name : null,
                    'item_code' => $voucherMeta->item ? $voucherMeta->item->item_code : null,
                    'category_name' => $voucherMeta->category ? $voucherMeta->category->name : null,
                    'quantity' => $voucherMeta->item_quantity,
                    'job_work_rate' => $voucherMeta->job_work_rate,
                    'scrap_wt' => $voucherMeta->scrap_wt,
                    'material_price' => $voucherMeta->material_price,
                    'gst_percent_rate' => $voucherMeta->gst_percent_rate,
                    'remark' => $voucherMeta->remark,
                    'total_price' => $totalPrice,
                    'vehicle_number' => $voucher->vehicle_number,
                    'description' => $voucher->description,
                ];
            }
        }
        
        // 6. Return the complete response
        return response()->json([
            'status' => 200,
            'message' => 'Sales report retrieved successfully',
            'data' => [
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
                'party' => $party ? [
                    'id' => $party->id,
                    'name' => $party->name,
                    'address_line_1' => $party->address1,
                    'address_line_2' => $party->address2,
                    'city' => $party->city,
                    'state' => $party->state->title,
                    'pincode' => $party->pincode,
                    'gst_number' => $party->gst_number,
                ] : null,
                'item' => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'code' => $item->item_code,
                    'hsn' => $item->hsn,
                    'description' => $item->description,
                ],
                'date_range' => [
                    'from' => $transactionDateFrom,
                    'to' => $transactionDateTo,
                ],
                'summary' => [
                    'total_sales' => (float) $totalSales,
                    'transaction_count' => count($transactionDetails),
                    'transaction_mr' => $transaction_mr,
                    'transaction_cr' => $transaction_cr,
                    'transaction_bh' => $transaction_bh,
                    'transaction_ok' => $transaction_ok,
                    'transaction_asitis' => $transaction_asitis,
                    'transaction_mr_qty' => $transaction_mr_qty,
                    'transaction_cr_qty' => $transaction_cr_qty,
                    'transaction_bh_qty' => $transaction_bh_qty,
                    'transaction_ok_qty' => $transaction_ok_qty,
                    'transaction_asitis_qty' => $transaction_asitis_qty
                ],
                'transactions' => $transactionDetails,
                'userSettings'=>$userSettings
            ],
        ]);
    }

    public function downloadSalesReport(Request $request)
    {
        $response = $this->checkPermission('Sales-Job-Work-Reports-Download');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();
        
        // Get request parameters
        $partyId = $request->input('party_id');
        $itemId = $request->input('item_id');
        $transactionDateFrom = $request->input('transaction_date_from');
        $transactionDateTo = $request->input('transaction_date_to');
        
        // Validate required parameters
        if (!$itemId) {
            return response()->json([
                'status' => 422,
                'message' => 'Item ID is required',
            ], 200);
        }
        
        // If dates are not provided, use default range (e.g., current financial year)
        if (!$transactionDateFrom || !$transactionDateTo) {
            // Determine current financial year dates
            $currentMonth = date('m');
            $currentYear = date('Y');
            
            if ($currentMonth < 4) {
                // Current FY is previous year April to current year March
                $transactionDateFrom = ($currentYear - 1) . '-04-01';
                $transactionDateTo = $currentYear . '-03-31';
            } else {
                // Current FY is current year April to next year March
                $transactionDateFrom = $currentYear . '-04-01';
                $transactionDateTo = ($currentYear + 1) . '-03-31';
            }
        }
        
        // 1. Get active company details
        $company = Company::with('state')->where('id', $activeCompanyId)
                        ->where('tenant_id', $tenantId)
                        ->first();
        
        if (!$company) {
            return response()->json([
                'status' => 422,
                'message' => 'Company not found',
            ], 200);
        }
        
        // 2. Get party details if party_id is provided
        $party = null;
        if ($partyId) {
            $party = Party::with('state')->where('id', $partyId)
                        ->where('tenant_id', $tenantId)
                        ->where('company_id', $activeCompanyId)
                        ->first();
            
            if (!$party) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Party not found',
                ], 200);
            }
        }
        
        // 3. Get item details
        $item = Item::where('id', $itemId)
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $activeCompanyId)
                    ->when($partyId, function($query) use ($partyId) {
                        return $query->whereJsonContains('party_id', (int) $partyId);
                    })
                    ->first();
        
        if (!$item) {
            return response()->json([
                'status' => 422,
                'message' => 'Item not found',
            ], 200);
        }
        
        // 4. Get all relevant transactions (voucher meta entries)
       $query = VoucherMeta::with(['voucher.party', 'category', 'item'])
            ->whereHas('voucher', function ($q) use ($tenantId, $activeCompanyId, $transactionDateFrom, $transactionDateTo, $partyId) {
                $q->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->where('transaction_type', 'outward') // Sales are outward transactions
                ->whereBetween('transaction_date', [$transactionDateFrom, $transactionDateTo]);
                
                if ($partyId) {
                    $q->where('party_id', $partyId);
                }
            })
            ->where('item_id', $itemId)
            ->join('voucher', 'voucher_meta.voucher_id', '=', 'voucher.id')
            ->orderBy('voucher.transaction_date', 'asc')
            ->select('voucher_meta.*');
        
        $voucherMetas = $query->get();
        
        // 5. Calculate total sales and prepare transaction details
        $totalSales = 0;
        $transaction_mr=0;
        $transaction_cr=0;
        $transaction_bh=0;
        $transaction_ok=0;
        $transaction_asitis=0;
        $transaction_mr_qty=0;
        $transaction_cr_qty=0;
        $transaction_bh_qty=0;
        $transaction_ok_qty=0;
        $transaction_asitis_qty=0;

        $transactionDetails = [];
        $userSettings = usersettings::where('tenant_id', $tenantId)
        ->where('user_id', $user->id)
        ->whereIn('slug', ['jobwork_inhouse_cr', 'jobwork_inhouse_mr', 'jobwork_inhouse_bh','jobwork_show_gst','include_jobwork_rate_in_report'])
        ->pluck('val', 'slug')
        ->toArray();

         // Ensure user settings are available for each condition
         $jobwork_inhouse_cr = $userSettings['jobwork_inhouse_cr'] ?? 'no';
         $jobwork_inhouse_mr = $userSettings['jobwork_inhouse_mr'] ?? 'no';
         $jobwork_inhouse_bh = $userSettings['jobwork_inhouse_bh'] ?? 'no';
         $jobwork_show_gst = $userSettings['jobwork_show_gst'] ?? 'no';
         $include_jobwork_rate_in_report = $userSettings['include_jobwork_rate_in_report'] ?? 'no';
        
        foreach ($voucherMetas as $voucherMeta) {
            $voucher = $voucherMeta->voucher;

            $rate = 0;
            $quantity = $voucherMeta->item_quantity ?? 0; // Default to 0 if null
            $remark = strtolower($voucherMeta->remark ?? ''); // Normalize remark to lowercase
            $gstpercentage = $voucherMeta->gst_percent_rate;
            //Determine the rate based on remark and user settings
            if ($remark === 'mr' && $jobwork_inhouse_mr === 'yes') {
                $rate = $voucherMeta->job_work_rate ?? 0;  // Use default if null
                $transaction_mr++;
                $transaction_mr_qty += $quantity;
            } elseif ($remark === 'cr' && $jobwork_inhouse_cr === 'yes') {
                $rate = $voucherMeta->job_work_rate ?? 0;
                $transaction_cr++;
                $transaction_cr_qty += $quantity;
            } elseif ($remark === 'bh' && $jobwork_inhouse_bh === 'yes') {
                $rate = $voucherMeta->job_work_rate ?? 0;
                $transaction_bh++;
                $transaction_bh_qty += $quantity;
            } elseif ($remark === 'ok') {
                $rate = $voucherMeta->job_work_rate ?? 0;
                $transaction_ok++;
                $transaction_ok_qty += $quantity;
            } elseif ($remark === 'as-it-is') {
                $rate = 0;
                $transaction_asitis++;
                $transaction_asitis_qty += $quantity;
            }

            //  if ($remark === 'mr' ) {
            //     if($jobwork_inhouse_mr === 'yes'){
            //         $rate = $voucherMeta->job_work_rate ?? 0;  // Use default if null
            //     }
            //     $transaction_mr++;
            //     $transaction_mr_qty += $quantity;
            // } elseif ($remark === 'cr' ) {
            //     if($jobwork_inhouse_cr === 'yes'){
            //         $rate = $voucherMeta->job_work_rate ?? 0;
            //     }
            //     $transaction_cr++;
            //     $transaction_cr_qty += $quantity;
            // } elseif ($remark === 'bh' ) {
            //     if($jobwork_inhouse_bh === 'yes'){
            //         $rate = $voucherMeta->job_work_rate ?? 0;
            //     }
            //     $transaction_bh++;
            //     $transaction_bh_qty += $quantity;
            // } elseif ($remark === 'ok') {
            //         $rate = $voucherMeta->job_work_rate ?? 0;
            //     $transaction_ok++;
            //     $transaction_ok_qty += $quantity;
            // } elseif ($remark === 'as-it-is') {
            //     $rate = 0;
            //     $transaction_asitis++;
            //     $transaction_asitis_qty += $quantity;
            // }
                
            $totalPrice = $rate * $quantity;
            if ($jobwork_show_gst === 'yes' && $gstpercentage > 0) {
                $totalPrice += ($totalPrice * $gstpercentage) / 100;
            }
            $totalSales += $totalPrice;

             // Determine if transaction should be shown based on remark and user settings
            switch ($remark) {
                case 'mr':
                    $showTransaction = ($jobwork_inhouse_mr === 'yes');
                    break;
                case 'cr':
                    $showTransaction = ($jobwork_inhouse_cr === 'yes');
                    break;
                case 'bh':
                    $showTransaction = ($jobwork_inhouse_bh === 'yes');
                    break;
                case 'ok':
                    $showTransaction = true; // Always show OK transactions
                    break;
                case 'as-it-is':
                    $showTransaction = ($quantity > 0); // Show only if quantity exists
                    break;
                default:
                    $showTransaction = true; // For any other remarks, show by default
            }


            if( $showTransaction)
            {
                $transactionDetails[] = [
                    'id' => $voucherMeta->id,
                    'voucher_id' => $voucherMeta->voucher_id,
                    'voucher_no' => $voucher->voucher_no,
                    'transaction_date' => $voucher->transaction_date,
                    'party_name' => $voucher->party ? $voucher->party->name : null,
                    'item_name' => $voucherMeta->item ? $voucherMeta->item->name : null,
                    'item_code' => $voucherMeta->item ? $voucherMeta->item->item_code : null,
                    'category_name' => $voucherMeta->category ? $voucherMeta->category->name : null,
                    'quantity' => $voucherMeta->item_quantity,
                    'job_work_rate' => $voucherMeta->job_work_rate,
                    'scrap_wt' => $voucherMeta->scrap_wt,
                    'material_price' => $voucherMeta->material_price,
                    'gst_percent_rate' => $voucherMeta->gst_percent_rate,
                    'remark' => $voucherMeta->remark,
                    'total_price' => $totalPrice,
                    'vehicle_number' => $voucher->vehicle_number,
                    'description' => $voucher->description,
                ];
            }
        }
        
        // 6. complete response
       
        $data = [
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
                'party' => $party ? [
                    'id' => $party->id,
                    'name' => $party->name,
                    'address_line_1' => $party->address1,
                    'address_line_2' => $party->address2,
                    'city' => $party->city,
                    'state' => $party->state->title,
                    'pincode' => $party->pincode,
                    'gst_number' => $party->gst_number,
                ] : null,
                'item' => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'code' => $item->item_code,
                    'hsn' => $item->hsn,
                    'description' => $item->description,
                ],
                'date_range' => [
                    'from' => $transactionDateFrom,
                    'to' => $transactionDateTo,
                ],
                'summary' => [
                    'total_sales' => (float) $totalSales,
                    'transaction_count' => count($transactionDetails),
                    'transaction_mr' => $transaction_mr,
                    'transaction_cr' => $transaction_cr,
                    'transaction_bh' => $transaction_bh,
                    'transaction_ok' => $transaction_ok,
                    'transaction_asitis' => $transaction_asitis,
                    'transaction_mr_qty' => $transaction_mr_qty,
                    'transaction_cr_qty' => $transaction_cr_qty,
                    'transaction_bh_qty' => $transaction_bh_qty,
                    'transaction_ok_qty' => $transaction_ok_qty,
                    'transaction_asitis_qty' => $transaction_asitis_qty
                ],
                'transactions' => $transactionDetails,
                'userSettings'=>$userSettings
            ];

        // Configure Dompdf
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans'); // Important for special characters

        // Instantiate Dompdf
        $pdf = new Dompdf($options);

        // Load the HTML from the Blade view
        $view = view('pdf.salesreport', ['data' => $data])->render();
        $pdf->loadHtml($view, 'UTF-8');

        // Set paper size and orientation
        $pdf->setPaper('A4', 'portrait');

        // Render the PDF
        $pdf->render();

        // Generate a dynamic filename
        $filename = "SalesReport_" . ($data['item']['code'] ?? 'item') . "_" . date('Ymd') . ".pdf";
        
        // Stream the PDF to the browser (inline view)
        return $pdf->stream($filename);  
       
    }

    public function purchasereportindex(Request $request)
    {
        $response = $this->checkPermission('Purchase-Job-Work-Reports-Menu');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        // Get per_page parameter or default to 10
        $perPage = $request->input('per_page', 10);
        // Get page parameter or default to 1
        $page = $request->input('page', 1);
        $search = $request->input('search');
        
        // Fetch filter parameters
        $partyId = $request->input('party_id');
        $itemId = $request->input('item_id');
        $categoryId = $request->input('category_id');
        $voucherId = $request->input('voucher_id');
        $transactionDateFrom = $request->input('transaction_date_from');
        $transactionDateTo = $request->input('transaction_date_to');

    
       

        // Start with the VoucherMeta query to get all meta records
        $query = VoucherMeta::with(['voucher.party', 'category', 'item'])
            ->whereHas('voucher', function ($q) use ($tenantId, $activeCompanyId) {
                $q->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->where('transaction_type', 's_inward'); // Filter for s_inward(subcontract inward) transactions only
            })
            ->join('voucher', 'voucher_meta.voucher_id', '=', 'voucher.id')
            ->orderBy('voucher.transaction_date', 'desc')
            ->select('voucher_meta.*');

        // Apply search
        if ($search) {
            $query->whereHas('voucher', function ($q) use ($search) {
                $q->where('voucher_no', 'like', "%{$search}%")
                ->orWhere('vehicle_number', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Apply filters
        if ($partyId) {
            $query->whereHas('voucher', function ($q) use ($partyId) {
                $q->where('party_id', $partyId);
            });
        }

        if ($itemId) {
            $query->where('item_id', $itemId);
        }

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        if ($voucherId) {
            $query->where('voucher_id', $voucherId);
        }

        if ($transactionDateFrom && $transactionDateTo) {
            $query->whereHas('voucher', function ($q) use ($transactionDateFrom, $transactionDateTo) {
                $q->whereBetween('transaction_date', [$transactionDateFrom, $transactionDateTo]);
            });
        } elseif ($transactionDateFrom) {
            $query->whereHas('voucher', function ($q) use ($transactionDateFrom) {
                $q->where('transaction_date', '>=', $transactionDateFrom);
            });
        } elseif ($transactionDateTo) {
            $query->whereHas('voucher', function ($q) use ($transactionDateTo) {
                $q->where('transaction_date', '<=', $transactionDateTo);
            });
        }

        // Execute paginated query with explicitly setting the page
        $voucherMetas = $query->paginate($perPage, ['*'], 'page', $page);

        $userSettings = usersettings::where('tenant_id', $tenantId)
        ->where('user_id', $user->id)
        ->whereIn('slug', ['jobwork_outsourcing_cr', 'jobwork_outsourcing_mr', 'jobwork_outsourcing_bh','jobwork_show_gst'])
        ->pluck('val', 'slug')
        ->toArray();

        // Prepare flattened data structure
        $flattenedRecords = $voucherMetas->map(function ($voucherMeta) use ($userSettings){
          
        

            $voucher = $voucherMeta->voucher;
            $rate = 0;
            $quantity = $voucherMeta->item_quantity ?? 0; // Default to 0 if null
            $remark = strtolower($voucherMeta->remark ?? ''); // Normalize remark to lowercase
            $gstpercentage = $voucherMeta->gst_percent_rate;
            
            // Ensure user settings are available for each condition
            $jobwork_outsourcing_cr = $userSettings['jobwork_outsourcing_cr'] ?? 'no';
            $jobwork_outsourcing_mr = $userSettings['jobwork_outsourcing_mr'] ?? 'no';
            $jobwork_outsourcing_bh = $userSettings['jobwork_outsourcing_bh'] ?? 'no';
            $jobwork_show_gst = $userSettings['jobwork_show_gst'] ?? 'no';
            
            // Determine the rate based on remark and user settings
            if ($remark === 'mr' && $jobwork_outsourcing_mr === 'yes') {
                $rate = $voucherMeta->job_work_rate ?? 0;  // Use default if null
            } elseif ($remark === 'cr' && $jobwork_outsourcing_cr === 'yes') {
                $rate = $voucherMeta->job_work_rate ?? 0;
            } elseif ($remark === 'bh' && $jobwork_outsourcing_bh === 'yes') {
                $rate = $voucherMeta->job_work_rate ?? 0;
            } elseif ($remark === 'ok') {
                $rate = $voucherMeta->job_work_rate ?? 0;
            } elseif ($remark === 'as-it-is') {
                $rate = 0;
            }
                
            $totalPrice = $rate * $quantity;

            // Add GST if applicable
            if ($jobwork_show_gst === 'yes' && $gstpercentage > 0) {
                $totalPrice += ($totalPrice * $gstpercentage) / 100;
            }
          
            return [
                // VoucherMeta details
                'id' => $voucherMeta->id,
                'voucher_id' => $voucherMeta->voucher_id,
                'item_id' => $voucherMeta->item_id,
                'category_id' => $voucherMeta->category_id,
                'quantity' => $voucherMeta->item_quantity,
                'job_work_rate' => $voucherMeta->job_work_rate,
                'scrap_wt' => $voucherMeta->scrap_wt,
                'material_price' => $voucherMeta->material_price,
                'gst_percent_rate' => $voucherMeta->gst_percent_rate,
                'remark' => $voucherMeta->remark,
                'Total_Price' => $totalPrice,

                // Voucher details
                'voucher_no' => $voucher->voucher_no,
                'transaction_date' => $voucher->transaction_date,
                'vehicle_number' => $voucher->vehicle_number,
                'description' => $voucher->description,
                
                // Party details
                'party_id' => $voucher->party_id,
                'party_name' => $voucher->party ? $voucher->party->name : null,
                
                
                // Item details
                'item_name' => $voucherMeta->item ? $voucherMeta->item->name : null,
                'item_code' => $voucherMeta->item ? $voucherMeta->item->item_code : null,
                
                // Category details
                'category_name' => $voucherMeta->category ? $voucherMeta->category->name : null,
            ];
        });

       

        // Return response with pagination metadata
        return response()->json([
            'status' => 200,
            'message' => 'purchase records retrieved successfully',
            'records' => $flattenedRecords, // Flattened records
            'pagination' => [
                'current_page' => $voucherMetas->currentPage(),
                'total_count' => $voucherMetas->total(),
                'per_page' => $voucherMetas->perPage(),
                'last_page' => $voucherMetas->lastPage(),
            ],
        ]);
    }

    public function getpurchaseReport(Request $request)
    {
        $response = $this->checkPermission('Purchase-Job-Work-Reports-Download');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();
        
        // Get request parameters
        $partyId = $request->input('party_id');
        $itemId = $request->input('item_id');
        $transactionDateFrom = $request->input('transaction_date_from');
        $transactionDateTo = $request->input('transaction_date_to');
        
        // Validate required parameters
        if (!$itemId) {
            return response()->json([
                'status' => 422,
                'message' => 'Item ID is required',
            ], 200);
        }
        
        // If dates are not provided, use default range (e.g., current financial year)
        if (!$transactionDateFrom || !$transactionDateTo) {
            // Determine current financial year dates
            $currentMonth = date('m');
            $currentYear = date('Y');
            
            if ($currentMonth < 4) {
                // Current FY is previous year April to current year March
                $transactionDateFrom = ($currentYear - 1) . '-04-01';
                $transactionDateTo = $currentYear . '-03-31';
            } else {
                // Current FY is current year April to next year March
                $transactionDateFrom = $currentYear . '-04-01';
                $transactionDateTo = ($currentYear + 1) . '-03-31';
            }
        }
        
        // 1. Get active company details
        $company = Company::with('state')->where('id', $activeCompanyId)
                        ->where('tenant_id', $tenantId)
                        ->first();
        
        if (!$company) {
            return response()->json([
                'status' => 422,
                'message' => 'Company not found',
            ], 200);
        }
        
        // 2. Get party details if party_id is provided
        $party = null;
        if ($partyId) {
            $party = Party::with('state')->where('id', $partyId)
                        ->where('tenant_id', $tenantId)
                        ->where('company_id', $activeCompanyId)
                        ->first();
            
            if (!$party) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Party not found',
                ], 200);
            }
        }
        
        // 3. Get item details
        $item = Item::where('id', $itemId)
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $activeCompanyId)
                    ->when($partyId, function($query) use ($partyId) {
                        return $query->whereJsonContains('party_id', (int) $partyId);
                    })
                    ->first();
        
        if (!$item) {
            return response()->json([
                'status' => 422,
                'message' => 'Item not found',
            ], 200);
        }
        
        // 4. Get all relevant transactions (voucher meta entries)
        $query = VoucherMeta::with(['voucher.party', 'category', 'item'])
            ->whereHas('voucher', function ($q) use ($tenantId, $activeCompanyId, $transactionDateFrom, $transactionDateTo, $partyId) {
                $q->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->where('transaction_type', 's_inward') // Sales are outward transactions
                ->whereBetween('transaction_date', [$transactionDateFrom, $transactionDateTo]);
                
                if ($partyId) {
                    $q->where('party_id', $partyId);
                }
            })
            ->where('item_id', $itemId)
            ->join('voucher', 'voucher_meta.voucher_id', '=', 'voucher.id')
            ->orderBy('voucher.transaction_date', 'asc')
            ->select('voucher_meta.*');
        
        $voucherMetas = $query->get();
        
        // 5. Calculate total sales and prepare transaction details
        $totalSales = 0;
        $transaction_mr=0;
        $transaction_cr=0;
        $transaction_bh=0;
        $transaction_ok=0;
        $transaction_asitis=0;
        $transaction_mr_qty=0;
        $transaction_cr_qty=0;
        $transaction_bh_qty=0;
        $transaction_ok_qty=0;
        $transaction_asitis_qty=0;
        $transactionDetails = [];
        $userSettings = usersettings::where('tenant_id', $tenantId)
        ->where('user_id', $user->id)
        ->whereIn('slug', ['jobwork_outsourcing_cr', 'jobwork_outsourcing_mr', 'jobwork_outsourcing_bh','jobwork_show_gst','include_jobwork_rate_in_report'])
        ->pluck('val', 'slug')
        ->toArray();

         // Ensure user settings are available for each condition
         $jobwork_outsourcing_cr = $userSettings['jobwork_outsourcing_cr'] ?? 'no';
         $jobwork_outsourcing_mr = $userSettings['jobwork_outsourcing_mr'] ?? 'no';
         $jobwork_outsourcing_bh = $userSettings['jobwork_outsourcing_bh'] ?? 'no';
         $jobwork_show_gst = $userSettings['jobwork_show_gst'] ?? 'no';
         $include_jobwork_rate_in_report = $userSettings['include_jobwork_rate_in_report'] ?? 'no';

        foreach ($voucherMetas as $voucherMeta) {
            $voucher = $voucherMeta->voucher;

            $rate = 0;
            $quantity = $voucherMeta->item_quantity ?? 0; // Default to 0 if null
            $remark = strtolower($voucherMeta->remark ?? ''); // Normalize remark to lowercase
            $gstpercentage = $voucherMeta->gst_percent_rate;
            // Determine the rate based on remark and user settings
            if ($remark === 'mr' && $jobwork_outsourcing_mr === 'yes') {
                $rate = $voucherMeta->job_work_rate ?? 0;  // Use default if null
                $transaction_mr++;
                $transaction_mr_qty += $quantity;
            } elseif ($remark === 'cr' && $jobwork_outsourcing_cr === 'yes') {
                $rate = $voucherMeta->job_work_rate ?? 0;
                $transaction_cr++;
                $transaction_cr_qty += $quantity;
            } elseif ($remark === 'bh' && $jobwork_outsourcing_bh === 'yes') {
                $rate = $voucherMeta->job_work_rate ?? 0;
                $transaction_bh++;
                $transaction_bh_qty += $quantity;
            } elseif ($remark === 'ok') {
                $rate = $voucherMeta->job_work_rate ?? 0;
                $transaction_ok++;
                $transaction_ok_qty += $quantity;
            } elseif ($remark === 'as-it-is') {
                $rate = 0;
                $transaction_asitis++;
                $transaction_asitis_qty += $quantity;
            }

            //  if ($remark === 'mr' ) {
            //     if($jobwork_outsourcing_mr === 'yes'){
            //         $rate = $voucherMeta->job_work_rate ?? 0;  // Use default if null
            //     }
            //     $transaction_mr++;
            //     $transaction_mr_qty += $quantity;
            // } elseif ($remark === 'cr' ) {
            //     if($jobwork_outsourcing_cr === 'yes'){
            //         $rate = $voucherMeta->job_work_rate ?? 0;
            //     }
            //     $transaction_cr++;
            //     $transaction_cr_qty += $quantity;
            // } elseif ($remark === 'bh' ) {
            //     if($jobwork_outsourcing_bh === 'yes'){
            //         $rate = $voucherMeta->job_work_rate ?? 0;
            //     }
            //     $transaction_bh++;
            //     $transaction_bh_qty += $quantity;
            // } elseif ($remark === 'ok') {
            //     $rate = $voucherMeta->job_work_rate ?? 0;
            //     $transaction_ok++;
            //     $transaction_ok_qty += $quantity;
            // } elseif ($remark === 'as-it-is') {
            //     $rate = 0;
            //     $transaction_asitis++;
            //     $transaction_asitis_qty += $quantity;
            // }
                
            $totalPrice = $rate * $quantity;
            if ($jobwork_show_gst === 'yes' && $gstpercentage > 0) {
                $totalPrice += ($totalPrice * $gstpercentage) / 100;
            }
            $totalSales += $totalPrice;

            switch ($remark) {
                case 'mr':
                    $showTransaction = ($jobwork_outsourcing_mr === 'yes');
                    break;
                case 'cr':
                    $showTransaction = ($jobwork_outsourcing_cr === 'yes');
                    break;
                case 'bh':
                    $showTransaction = ($jobwork_outsourcing_bh === 'yes');
                    break;
                case 'ok':
                    $showTransaction = true; // Always show OK transactions
                    break;
                case 'as-it-is':
                    $showTransaction = ($quantity > 0); // Show only if quantity exists
                    break;
                default:
                    $showTransaction = true; // For any other remarks, show by default
            }
            
            if($showTransaction)
            {
                $transactionDetails[] = [
                    'id' => $voucherMeta->id,
                    'voucher_id' => $voucherMeta->voucher_id,
                    'voucher_no' => $voucher->voucher_no,
                    'transaction_date' => $voucher->transaction_date,
                    'party_name' => $voucher->party ? $voucher->party->name : null,
                    'item_name' => $voucherMeta->item ? $voucherMeta->item->name : null,
                    'item_code' => $voucherMeta->item ? $voucherMeta->item->item_code : null,
                    'category_name' => $voucherMeta->category ? $voucherMeta->category->name : null,
                    'quantity' => $voucherMeta->item_quantity,
                    'job_work_rate' => $voucherMeta->job_work_rate,
                    'scrap_wt' => $voucherMeta->scrap_wt,
                    'material_price' => $voucherMeta->material_price,
                    'gst_percent_rate' => $voucherMeta->gst_percent_rate,
                    'remark' => $voucherMeta->remark,
                    'total_price' => $totalPrice,
                    'vehicle_number' => $voucher->vehicle_number,
                    'description' => $voucher->description,
                ];
            }
        }
        
        // 6. Return the complete response
        return response()->json([
            'status' => 200,
            'message' => 'purchase report retrieved successfully',
            'data' => [
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
                'party' => $party ? [
                    'id' => $party->id,
                    'name' => $party->name,
                    'address_line_1' => $party->address1,
                    'address_line_2' => $party->address2,
                    'city' => $party->city,
                    'state' => $party->state->title,
                    'pincode' => $party->pincode,
                    'gst_number' => $party->gst_number,
                ] : null,
                'item' => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'code' => $item->item_code,
                    'hsn' => $item->hsn,
                    'description' => $item->description,
                ],
                'date_range' => [
                    'from' => $transactionDateFrom,
                    'to' => $transactionDateTo,
                ],
                'summary' => [
                    'total_sales' => (float) $totalSales,
                    'transaction_count' => count($transactionDetails),
                    'transaction_mr' => $transaction_mr,
                    'transaction_cr' => $transaction_cr,
                    'transaction_bh' => $transaction_bh,
                    'transaction_ok' => $transaction_ok,
                    'transaction_asitis' => $transaction_asitis,
                    'transaction_mr_qty' => $transaction_mr_qty,
                    'transaction_cr_qty' => $transaction_cr_qty,
                    'transaction_bh_qty' => $transaction_bh_qty,
                    'transaction_ok_qty' => $transaction_ok_qty,
                    'transaction_asitis_qty' => $transaction_asitis_qty
                ],
                'transactions' => $transactionDetails,
                'userSettings'=>$userSettings
            ],
        ]);
    }

    public function downloadpurchaseReport(Request $request)
    {
        $response = $this->checkPermission('Purchase-Job-Work-Reports-Download');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();
        
        // Get request parameters
        $partyId = $request->input('party_id');
        $itemId = $request->input('item_id');
        $transactionDateFrom = $request->input('transaction_date_from');
        $transactionDateTo = $request->input('transaction_date_to');
        
        // Validate required parameters
        if (!$itemId) {
            return response()->json([
                'status' => 422,
                'message' => 'Item ID is required',
            ], 200);
        }
        
        // If dates are not provided, use default range (e.g., current financial year)
        if (!$transactionDateFrom || !$transactionDateTo) {
            // Determine current financial year dates
            $currentMonth = date('m');
            $currentYear = date('Y');
            
            if ($currentMonth < 4) {
                // Current FY is previous year April to current year March
                $transactionDateFrom = ($currentYear - 1) . '-04-01';
                $transactionDateTo = $currentYear . '-03-31';
            } else {
                // Current FY is current year April to next year March
                $transactionDateFrom = $currentYear . '-04-01';
                $transactionDateTo = ($currentYear + 1) . '-03-31';
            }
        }
        
        // 1. Get active company details
        $company = Company::with('state')->where('id', $activeCompanyId)
                        ->where('tenant_id', $tenantId)
                        ->first();
        
        if (!$company) {
            return response()->json([
                'status' => 422,
                'message' => 'Company not found',
            ], 200);
        }
        
        // 2. Get party details if party_id is provided
        $party = null;
        if ($partyId) {
            $party = Party::with('state')->where('id', $partyId)
                        ->where('tenant_id', $tenantId)
                        ->where('company_id', $activeCompanyId)
                        ->first();
            
            if (!$party) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Party not found',
                ], 200);
            }
        }
        
        // 3. Get item details
        $item = Item::where('id', $itemId)
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $activeCompanyId)
                    ->when($partyId, function($query) use ($partyId) {
                        return $query->whereJsonContains('party_id', (int) $partyId);
                    })
                    ->first();
        
        if (!$item) {
            return response()->json([
                'status' => 422,
                'message' => 'Item not found',
            ], 200);
        }
        
        // 4. Get all relevant transactions (voucher meta entries)
        $query = VoucherMeta::with(['voucher.party', 'category', 'item'])
            ->whereHas('voucher', function ($q) use ($tenantId, $activeCompanyId, $transactionDateFrom, $transactionDateTo, $partyId) {
                $q->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->where('transaction_type', 's_inward') // Sales are outward transactions
                ->whereBetween('transaction_date', [$transactionDateFrom, $transactionDateTo]);
                
                if ($partyId) {
                    $q->where('party_id', $partyId);
                }
            })
            ->where('item_id', $itemId)
            ->join('voucher', 'voucher_meta.voucher_id', '=', 'voucher.id')
            ->orderBy('voucher.transaction_date', 'asc')
            ->select('voucher_meta.*');
        
        $voucherMetas = $query->get();
        
        // 5. Calculate total sales and prepare transaction details
        $totalSales = 0;
        $transaction_mr=0;
        $transaction_cr=0;
        $transaction_bh=0;
        $transaction_ok=0;
        $transaction_asitis=0;
        $transaction_mr_qty=0;
        $transaction_cr_qty=0;
        $transaction_bh_qty=0;
        $transaction_ok_qty=0;
        $transaction_asitis_qty=0;
        $transactionDetails = [];
        $userSettings = usersettings::where('tenant_id', $tenantId)
        ->where('user_id', $user->id)
        ->whereIn('slug', ['jobwork_outsourcing_cr', 'jobwork_outsourcing_mr', 'jobwork_outsourcing_bh','jobwork_show_gst','include_jobwork_rate_in_report'])
        ->pluck('val', 'slug')
        ->toArray();

         // Ensure user settings are available for each condition
         $jobwork_outsourcing_cr = $userSettings['jobwork_outsourcing_cr'] ?? 'no';
         $jobwork_outsourcing_mr = $userSettings['jobwork_outsourcing_mr'] ?? 'no';
         $jobwork_outsourcing_bh = $userSettings['jobwork_outsourcing_bh'] ?? 'no';
         $jobwork_show_gst = $userSettings['jobwork_show_gst'] ?? 'no';
         $include_jobwork_rate_in_report = $userSettings['include_jobwork_rate_in_report'] ?? 'no';

        foreach ($voucherMetas as $voucherMeta) {
            $voucher = $voucherMeta->voucher;

            $rate = 0;
            $quantity = $voucherMeta->item_quantity ?? 0; // Default to 0 if null
            $remark = strtolower($voucherMeta->remark ?? ''); // Normalize remark to lowercase
            $gstpercentage = $voucherMeta->gst_percent_rate;
            // Determine the rate based on remark and user settings
            if ($remark === 'mr' && $jobwork_outsourcing_mr === 'yes') {
                $rate = $voucherMeta->job_work_rate ?? 0;  // Use default if null
                $transaction_mr++;
                $transaction_mr_qty += $quantity;
            } elseif ($remark === 'cr' && $jobwork_outsourcing_cr === 'yes') {
                $rate = $voucherMeta->job_work_rate ?? 0;
                $transaction_cr++;
                $transaction_cr_qty += $quantity;
            } elseif ($remark === 'bh' && $jobwork_outsourcing_bh === 'yes') {
                $rate = $voucherMeta->job_work_rate ?? 0;
                $transaction_bh++;
                $transaction_bh_qty += $quantity;
            } elseif ($remark === 'ok') {
                $rate = $voucherMeta->job_work_rate ?? 0;
                $transaction_ok++;
                $transaction_ok_qty += $quantity;
            } elseif ($remark === 'as-it-is') {
                $rate = 0;
                $transaction_asitis++;
                $transaction_asitis_qty += $quantity;
            }

            //  if ($remark === 'mr' ) {
            //     if($jobwork_outsourcing_mr === 'yes'){
            //         $rate = $voucherMeta->job_work_rate ?? 0;  // Use default if null
            //     }
            //     $transaction_mr++;
            //     $transaction_mr_qty += $quantity;
            // } elseif ($remark === 'cr' ) {
            //     if($jobwork_outsourcing_cr === 'yes'){
            //         $rate = $voucherMeta->job_work_rate ?? 0;
            //     }
            //     $transaction_cr++;
            //     $transaction_cr_qty += $quantity;
            // } elseif ($remark === 'bh' ) {
            //     if($jobwork_outsourcing_bh === 'yes'){
            //         $rate = $voucherMeta->job_work_rate ?? 0;
            //     }
            //     $transaction_bh++;
            //     $transaction_bh_qty += $quantity;
            // } elseif ($remark === 'ok') {
            //     $rate = $voucherMeta->job_work_rate ?? 0;
            //     $transaction_ok++;
            //     $transaction_ok_qty += $quantity;
            // } elseif ($remark === 'as-it-is') {
            //     $rate = 0;
            //     $transaction_asitis++;
            //     $transaction_asitis_qty += $quantity;
            // }
                
            $totalPrice = $rate * $quantity;
            if ($jobwork_show_gst === 'yes' && $gstpercentage > 0) {
                $totalPrice += ($totalPrice * $gstpercentage) / 100;
            }
            $totalSales += $totalPrice;

            switch ($remark) {
                case 'mr':
                    $showTransaction = ($jobwork_outsourcing_mr === 'yes');
                    break;
                case 'cr':
                    $showTransaction = ($jobwork_outsourcing_cr === 'yes');
                    break;
                case 'bh':
                    $showTransaction = ($jobwork_outsourcing_bh === 'yes');
                    break;
                case 'ok':
                    $showTransaction = true; // Always show OK transactions
                    break;
                case 'as-it-is':
                    $showTransaction = ($quantity > 0); // Show only if quantity exists
                    break;
                default:
                    $showTransaction = true; // For any other remarks, show by default
            }
            
            if($showTransaction)
            {
                $transactionDetails[] = [
                    'id' => $voucherMeta->id,
                    'voucher_id' => $voucherMeta->voucher_id,
                    'voucher_no' => $voucher->voucher_no,
                    'transaction_date' => $voucher->transaction_date,
                    'party_name' => $voucher->party ? $voucher->party->name : null,
                    'item_name' => $voucherMeta->item ? $voucherMeta->item->name : null,
                    'item_code' => $voucherMeta->item ? $voucherMeta->item->item_code : null,
                    'category_name' => $voucherMeta->category ? $voucherMeta->category->name : null,
                    'quantity' => $voucherMeta->item_quantity,
                    'job_work_rate' => $voucherMeta->job_work_rate,
                    'scrap_wt' => $voucherMeta->scrap_wt,
                    'material_price' => $voucherMeta->material_price,
                    'gst_percent_rate' => $voucherMeta->gst_percent_rate,
                    'remark' => $voucherMeta->remark,
                    'total_price' => $totalPrice,
                    'vehicle_number' => $voucher->vehicle_number,
                    'description' => $voucher->description,
                ];
            }
        }
        
        // 6.  response
        $data = [
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
                'party' => $party ? [
                    'id' => $party->id,
                    'name' => $party->name,
                    'address_line_1' => $party->address1,
                    'address_line_2' => $party->address2,
                    'city' => $party->city,
                    'state' => $party->state->title,
                    'pincode' => $party->pincode,
                    'gst_number' => $party->gst_number,
                ] : null,
                'item' => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'code' => $item->item_code,
                    'hsn' => $item->hsn,
                    'description' => $item->description,
                ],
                'date_range' => [
                    'from' => $transactionDateFrom,
                    'to' => $transactionDateTo,
                ],
                'summary' => [
                    'total_sales' => (float) $totalSales,
                    'transaction_count' => count($transactionDetails),
                    'transaction_mr' => $transaction_mr,
                    'transaction_cr' => $transaction_cr,
                    'transaction_bh' => $transaction_bh,
                    'transaction_ok' => $transaction_ok,
                    'transaction_asitis' => $transaction_asitis,
                    'transaction_mr_qty' => $transaction_mr_qty,
                    'transaction_cr_qty' => $transaction_cr_qty,
                    'transaction_bh_qty' => $transaction_bh_qty,
                    'transaction_ok_qty' => $transaction_ok_qty,
                    'transaction_asitis_qty' => $transaction_asitis_qty
                ],
                'transactions' => $transactionDetails,
                'userSettings'=>$userSettings
            ];

        // Configure Dompdf
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans'); // Important for special characters

        // Instantiate Dompdf
        $pdf = new Dompdf($options);

        // Load the HTML from the Blade view
        $view = view('pdf.purchasereport', ['data' => $data])->render();
        $pdf->loadHtml($view, 'UTF-8');

        // Set paper size and orientation
        $pdf->setPaper('A4', 'portrait');

        // Render the PDF
        $pdf->render();

        // Generate a dynamic filename
        $filename = "PurchaseReport_" . ($data['item']['code'] ?? 'item') . "_" . date('Ymd') . ".pdf";
        
        // Stream the PDF to the browser (inline view)
        return $pdf->stream($filename);  
       
        
    }

    public function scrapreturnreportindex(Request $request)
    {
        $response = $this->checkPermission('Scrap-Return-Reports-Menu');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        // Get per_page parameter or default to 10
        $perPage = $request->input('per_page', 10);
        // Get page parameter or default to 1
        $page = $request->input('page', 1);
        $search = $request->input('search');
        
        // Fetch filter parameters
        $partyId = $request->input('party_id');
        $itemId = $request->input('item_id');
        $categoryId = $request->input('category_id');
        $voucherId = $request->input('voucher_id');
        $transactionDateFrom = $request->input('transaction_date_from');
        $transactionDateTo = $request->input('transaction_date_to');

        // Get user settings for scrap handling
        $userSettings = usersettings::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->whereIn('slug', ['scrap_inhouse_cr', 'scrap_inhouse_mr', 'scrap_inhouse_bh'])
            ->pluck('val', 'slug')
            ->toArray();

        // Start with the VoucherMeta query to get all outward transactions
        $voucherMetaQuery = VoucherMeta::with(['voucher.party', 'category', 'item'])
            ->whereHas('voucher', function ($q) use ($tenantId, $activeCompanyId) {
                $q->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->where('transaction_type', 'outward');
            })
            ->join('voucher', 'voucher_meta.voucher_id', '=', 'voucher.id')
            ->orderBy('voucher.transaction_date', 'desc')
            ->select('voucher_meta.*');

        // Apply search and filters to voucher meta
        $this->applyFilters($voucherMetaQuery, $search, $partyId, $itemId, $categoryId, $voucherId, $transactionDateFrom, $transactionDateTo);

        // Execute voucher meta query without pagination to combine with scrap transactions
        $voucherMetas = $voucherMetaQuery->get();

        // Start with the ScrapTransaction query to get all scrap transactions
        $scrapTransactionsQuery = DB::table('scrap_transactions')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->whereIn('scrap_type',['outward', 'adjustment'])
            ->orderBy('date', 'desc');

        // Apply date filters to scrap transactions
        if ($transactionDateFrom && $transactionDateTo) {
            $scrapTransactionsQuery->whereBetween('date', [$transactionDateFrom, $transactionDateTo]);
        } elseif ($transactionDateFrom) {
            $scrapTransactionsQuery->where('date', '>=', $transactionDateFrom);
        } elseif ($transactionDateTo) {
            $scrapTransactionsQuery->where('date', '<=', $transactionDateTo);
        }

        // Apply party filter to scrap transactions
        if ($partyId) {
            $scrapTransactionsQuery->where('party_id', $partyId);
        }

        // Apply search filter to scrap transactions
        if ($search) {
            $scrapTransactionsQuery->where(function ($q) use ($search) {
                $q->where('voucher_number', 'like', "%{$search}%")
                ->orWhere('vehical_number', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Execute scrap transactions query
        $scrapTransactions = $scrapTransactionsQuery->get();

        // Initialize combined records and counters
        $combinedRecords = [];
        $summary = [
            'scrap_due' => 0,
            'scrap_given' => 0,
            'scrap_balance' =>0,
            'transaction_count' => 0,
            'transaction_mr' => 0,
            'transaction_cr' => 0,
            'transaction_bh' => 0,
            'transaction_ok' => 0,
            'transaction_asitis' => 0,
            'transaction_mr_qty'=> 0,
            'transaction_cr_qty'=> 0,
            'transaction_bh_qty'=> 0,
            'transaction_ok_qty'=> 0,
            'transaction_asitis_qty'=> 0,
        ];

        // Process voucher transactions
        foreach ($voucherMetas as $voucherMeta) {
            $voucher = $voucherMeta->voucher;
            $scrapValue = $this->calculateScrapValue($voucherMeta, $userSettings);
            
            // Update counters based on remark
            $remark = strtolower($voucherMeta->remark ?? '');
            switch ($remark) {
                case 'mr':
                    $summary['transaction_mr']++;
                    $summary['transaction_mr_qty'] += $voucherMeta->item_quantity;
                    break;
                case 'cr':
                    $summary['transaction_cr']++;
                    $summary['transaction_cr_qty'] += $voucherMeta->item_quantity;
                    break;
                case 'bh':
                    $summary['transaction_bh']++;
                    $summary['transaction_bh_qty'] += $voucherMeta->item_quantity;
                    break;
                case 'ok':
                    $summary['transaction_ok']++;
                    $summary['transaction_ok_qty'] += $voucherMeta->item_quantity;
                    break;
                case 'as-it-is':
                    $summary['transaction_asitis']++;
                    $summary['transaction_asitis_qty'] += $voucherMeta->item_quantity;
                    break;
            }

            $summary['scrap_due'] += $scrapValue;
            $summary['transaction_count']++;

            $combinedRecords[] = [
                'id' => $voucherMeta->id,
                'date' => $voucher->transaction_date,
                'voucher_type' => 'outward',
                'voucher_no' => $voucher->voucher_no,
                'party_id' => $voucher->party_id,
                'party_name' => $voucher->party ? $voucher->party->name : null,
                'quantity' => $voucherMeta->item_quantity,
                'scrap_due' => $scrapValue,
                'scrap_given' => 0, // No scrap given for outward transactions
                'balance' => $scrapValue, // Initial balance is equal to scrap_due
                'vehicle_number' => $voucher->vehicle_number,
                'description' => $voucher->description,
                'item_id' => $voucherMeta->item_id,
                'item_name' => $voucherMeta->item ? $voucherMeta->item->name : null,
                'item_code' => $voucherMeta->item ? $voucherMeta->item->item_code : null,
                'category_id' => $voucherMeta->category_id,
                'category_name' => $voucherMeta->category ? $voucherMeta->category->name : null,
                'remark' => $voucherMeta->remark,
            ];
        }

        // Process scrap transactions
        foreach ($scrapTransactions as $scrapTransaction) {
            $summary['scrap_given'] += $scrapTransaction->scrap_weight;
            $summary['transaction_count']++;

            $combinedRecords[] = [
                'id' => $scrapTransaction->id,
                'date' => $scrapTransaction->date,
                'voucher_type' => 'return scrap',
                'voucher_no' => $scrapTransaction->voucher_number,
                'party_id' => $scrapTransaction->party_id,
                'party_name' => $this->getPartyName($scrapTransaction->party_id), // You'll need to implement this method
                'quantity' => null, // Not applicable for scrap transactions
                'scrap_due' => 0, // No scrap due for return transactions
                'scrap_given' => $scrapTransaction->scrap_weight,
                'balance' => -$scrapTransaction->scrap_weight, // Negative balance for returned scrap
                'vehicle_number' => $scrapTransaction->vehical_number,
                'description' => $scrapTransaction->description,
                'item_id' => null, // Not applicable for scrap transactions
                'item_name' => null,
                'item_code' => null,
                'category_id' => null,
                'category_name' => null,
                'remark' => "Scrap returned",
            ];
        }

        // Sort combined records by date
        usort($combinedRecords, function($a, $b) {
             return strtotime($b['date']) - strtotime($a['date']);
        });

        // Calculate running balance
        $runningBalance = 0;
        foreach ($combinedRecords as &$record) {
            $runningBalance += $record['scrap_due'] - $record['scrap_given'];
            $record['balance'] = $runningBalance;
            $summary['scrap_balance'] = $record['balance'] ;
        }

        // Paginate the combined records manually
        $total = count($combinedRecords);
        $lastPage = ceil($total / $perPage);
        $offset = ($page - 1) * $perPage;
        $paginatedRecords = array_slice($combinedRecords, $offset, $perPage);

       


        // Return response with pagination metadata
        return response()->json([
            'status' => 200,
            'message' => 'Scrap records retrieved successfully',
            'records' => $paginatedRecords,
            'summary' => $summary,
            'pagination' => [
                'current_page' => (int)$page,
                'total_count' => $total,
                'per_page' => (int)$perPage,
                'last_page' => $lastPage,
            ],
        ]);
    }

    /**
     * Apply common filters to a VoucherMeta query
     */
    private function applyFilters($query, $search, $partyId, $itemId, $categoryId, $voucherId, $transactionDateFrom, $transactionDateTo)
    {
        // Apply search
        if ($search) {
            $query->whereHas('voucher', function ($q) use ($search) {
                $q->where('voucher_no', 'like', "%{$search}%")
                ->orWhere('vehicle_number', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Apply filters
        if ($partyId) {
            $query->whereHas('voucher', function ($q) use ($partyId) {
                $q->where('party_id', $partyId);
            });
        }

        if ($itemId) {
            $query->where('item_id', $itemId);
        }

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        if ($voucherId) {
            $query->where('voucher_id', $voucherId);
        }

        if ($transactionDateFrom && $transactionDateTo) {
            $query->whereHas('voucher', function ($q) use ($transactionDateFrom, $transactionDateTo) {
                $q->whereBetween('transaction_date', [$transactionDateFrom, $transactionDateTo]);
            });
        } elseif ($transactionDateFrom) {
            $query->whereHas('voucher', function ($q) use ($transactionDateFrom) {
                $q->where('transaction_date', '>=', $transactionDateFrom);
            });
        } elseif ($transactionDateTo) {
            $query->whereHas('voucher', function ($q) use ($transactionDateTo) {
                $q->where('transaction_date', '<=', $transactionDateTo);
            });
        }
    }

    /**
     * Calculate scrap value based on voucher meta and user settings
     */
    private function calculateScrapValue($voucherMeta, $userSettings)
    {
        $scrapValue = 0;
        $remark = strtolower($voucherMeta->remark ?? ''); // Normalize remark to lowercase
        $quantity = $voucherMeta->item_quantity ?? 0;
        // Ensure user settings are available for each condition
        $scrap_inhouse_cr = $userSettings['scrap_inhouse_cr'] ?? 'no';
        $scrap_inhouse_mr = $userSettings['scrap_inhouse_mr'] ?? 'no';
        $scrap_inhouse_bh = $userSettings['scrap_inhouse_bh'] ?? 'no';
        
        // Determine if scrap_wt should be used based on remark and user settings
        if ($remark === 'mr' && $scrap_inhouse_mr === 'yes') {
            $scrapValue = ($voucherMeta->scrap_wt * $quantity) ?? 0;
        } elseif ($remark === 'cr' && $scrap_inhouse_cr === 'yes') {
            $scrapValue = ($voucherMeta->scrap_wt * $quantity) ?? 0;
        } elseif ($remark === 'bh' && $scrap_inhouse_bh === 'yes') {
            $scrapValue =($voucherMeta->scrap_wt * $quantity) ?? 0;
        } elseif ($remark === 'ok') {
            $scrapValue = ($voucherMeta->scrap_wt * $quantity) ?? 0;
        } elseif ($remark === 'as-it-is') {
            $scrapValue = 0;
        }
        
        return $scrapValue;
    }

    /**
     * Get party name by ID
     */
    private function getPartyName($partyId)
    {
        $party = DB::table('party')->where('id', $partyId)->first();
        return $party ? $party->name : null;
    }

    // Calculate opening balance (transactions before the start date)
    private function calculateOpeningBalance($tenantId, $activeCompanyId, $partyId, $itemId, $transactionDateFrom)
    {
        $openingBalance = [
            'scrap_due' => 0,
            'scrap_given' => 0,
            'balance' => 0
        ];
        
        // Get user settings for scrap handling
        $userSettings = usersettings::where('tenant_id', $tenantId)
            ->where('user_id', auth()->id())
            ->whereIn('slug', ['scrap_inhouse_cr', 'scrap_inhouse_mr', 'scrap_inhouse_bh'])
            ->pluck('val', 'slug')
            ->toArray();
        
        // Calculate scrap due from outward transactions before start date
        $voucherMetaQuery = VoucherMeta::with(['voucher.party', 'category', 'item'])
            ->whereHas('voucher', function ($q) use ($tenantId, $activeCompanyId, $transactionDateFrom, $partyId) {
                $q->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->where('transaction_type', 'outward')
                ->where('transaction_date', '<', $transactionDateFrom);
                
                if ($partyId) {
                    $q->where('party_id', $partyId);
                }
            });
        
        if ($itemId) {
            $voucherMetaQuery->where('item_id', $itemId);
        }
        
        $voucherMetas = $voucherMetaQuery->get();
        
        foreach ($voucherMetas as $voucherMeta) {
            $scrapValue = $this->calculateScrapValue($voucherMeta, $userSettings);
            $openingBalance['scrap_due'] += $scrapValue;
        }
        
        // Calculate scrap given from scrap transactions before start date
        $scrapTransactionsQuery = DB::table('scrap_transactions')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->whereIn('scrap_type', ['outward', 'adjustment'])
            ->where('date', '<', $transactionDateFrom);
        
        if ($partyId) {
            $scrapTransactionsQuery->where('party_id', $partyId);
        }
        
        $scrapTransactions = $scrapTransactionsQuery->get();
        
        foreach ($scrapTransactions as $scrapTransaction) {
            $openingBalance['scrap_given'] += $scrapTransaction->scrap_weight;
        }
        
        // Calculate net opening balance
        $openingBalance['balance'] = $openingBalance['scrap_due'] - $openingBalance['scrap_given'];
        
        return $openingBalance;
    }

    public function scrapreturnreportpdf(Request $request)
    {
        $response = $this->checkPermission('Scrap-Return-Reports-Download');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        $search = $request->input('search');
        
        // Fetch filter parameters
        $partyId = $request->input('party_id');
        $itemId = $request->input('item_id');
        $categoryId = $request->input('category_id');
        $voucherId = $request->input('voucher_id');
        $transactionDateFrom = $request->input('transaction_date_from');
        $transactionDateTo = $request->input('transaction_date_to');

        // Date validation logic
        if (($transactionDateFrom && !$transactionDateTo) || (!$transactionDateFrom && $transactionDateTo)) {
            return response()->json([
                'status' => 422,
                'message' => 'Both start and end dates must be provided',
            ], 200);
        }

        // If dates are not provided, use default range (current financial year)
        if (!$transactionDateFrom || !$transactionDateTo) {
            // Determine current financial year dates
            $currentMonth = date('m');
            $currentYear = date('Y');
            
            if ($currentMonth < 4) {
                // Current FY is previous year April to current year March
                $transactionDateFrom = ($currentYear - 1) . '-04-01';
                $transactionDateTo = $currentYear . '-03-31';
            } else {
                // Current FY is current year April to next year March
                $transactionDateFrom = $currentYear . '-04-01';
                $transactionDateTo = ($currentYear + 1) . '-03-31';
            }
        } else {
            // Validate date range - should not exceed 365 days
            $fromDate = new \DateTime($transactionDateFrom);
            $toDate = new \DateTime($transactionDateTo);
            $daysDifference = $fromDate->diff($toDate)->days;
            
            if ($daysDifference > 365) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Date range cannot exceed 365 days',
                ], 200);
            }
        }


         // 1. Get active company details
        $company = Company::with('state')->where('id', $activeCompanyId)
                        ->where('tenant_id', $tenantId)
                        ->first();
        
        if (!$company) {
            return response()->json([
                'status' => 422,
                'message' => 'Company not found',
            ], 200);
        }
        
        // 2. Get party details if party_id is provided
        $party = null;
        if ($partyId) {
            $party = Party::with('state')->where('id', $partyId)
                        ->where('tenant_id', $tenantId)
                        ->where('company_id', $activeCompanyId)
                        ->first();
            
            if (!$party) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Party not found',
                ], 200);
            }
        }
        
        // 3. Get item details
        $item = Item::where('id', $itemId)
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $activeCompanyId)
                    ->when($partyId, function($query) use ($partyId) {
                        return $query->whereJsonContains('party_id', (int) $partyId);
                    })
                    ->first();
        
        if (!$item) {
            return response()->json([
                'status' => 422,
                'message' => 'Item not found',
            ], 200);
        }
        

        // Get user settings for scrap handling
        $userSettings = usersettings::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->whereIn('slug', ['scrap_inhouse_cr', 'scrap_inhouse_mr', 'scrap_inhouse_bh','include_values_in_scrap_return'])
            ->pluck('val', 'slug')
            ->toArray();

         // Ensure user settings are available for each condition
        $scrap_inhouse_cr = $userSettings['scrap_inhouse_cr'] ?? 'no';
        $scrap_inhouse_mr = $userSettings['scrap_inhouse_mr'] ?? 'no';
        $scrap_inhouse_bh = $userSettings['scrap_inhouse_bh'] ?? 'no';

      


        // Start with the VoucherMeta query to get all outward transactions
        $voucherMetaQuery = VoucherMeta::with(['voucher.party', 'category', 'item'])
            ->whereHas('voucher', function ($q) use ($tenantId, $activeCompanyId) {
                $q->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->where('transaction_type', 'outward');
            })
            ->where('remark', '!=', 'as-it-is') 
            ->join('voucher', 'voucher_meta.voucher_id', '=', 'voucher.id')
            ->orderBy('voucher.transaction_date', 'asc')
            ->select('voucher_meta.*');


        // Apply search and filters to voucher meta
        $this->applyFilters($voucherMetaQuery, $search, $partyId, $itemId, $categoryId, $voucherId, $transactionDateFrom, $transactionDateTo);

        // Execute voucher meta query
        $voucherMetas = $voucherMetaQuery->get();

        // Start with the ScrapTransaction query to get all scrap transactions
        $scrapTransactionsQuery = DB::table('scrap_transactions')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->whereIn('scrap_type',['outward', 'adjustment'])
            ->orderBy('date', 'asc');

        // Apply date filters to scrap transactions
        if ($transactionDateFrom && $transactionDateTo) {
            $scrapTransactionsQuery->whereBetween('date', [$transactionDateFrom, $transactionDateTo]);
        } elseif ($transactionDateFrom) {
            $scrapTransactionsQuery->where('date', '>=', $transactionDateFrom);
        } elseif ($transactionDateTo) {
            $scrapTransactionsQuery->where('date', '<=', $transactionDateTo);
        }

        // Apply party filter to scrap transactions
        if ($partyId) {
            $scrapTransactionsQuery->where('party_id', $partyId);
        }

        

        // Execute scrap transactions query
        $scrapTransactions = $scrapTransactionsQuery->get();

        // Initialize combined records and counters
        $combinedRecords = [];
        $summary = [
            'scrap_due' => 0,
            'scrap_given' => 0,
            'scrap_balance' =>0,
            'scrap_transaction_count' =>0,
            'transaction_count' => 0,
            'transaction_mr' => 0,
            'transaction_cr' => 0,
            'transaction_bh' => 0,
            'transaction_ok' => 0,
            'transaction_asitis' => 0,
            'transaction_mr_qty'=> 0,
            'transaction_cr_qty'=> 0,
            'transaction_bh_qty'=> 0,
            'transaction_ok_qty'=> 0,
            'transaction_asitis_qty'=> 0,
        ];

        // Calculate opening balance as of transaction date from
        $openingBalance = $this->calculateOpeningBalance($tenantId, $activeCompanyId, $partyId, $itemId, $transactionDateFrom);

        // Add opening balance entry at the beginning of combined records
            $combinedRecords[] = [
                'id' => 'opening-balance',
                'date' => $transactionDateFrom, // Start date of the report
                'voucher_type' => 'opening',
                'voucher_no' => '-',
                'party_id' => $partyId,
                'party_name' => $party ? $party->name : 'All Parties',
                'quantity' => null,
                'scrap_due' => $openingBalance['scrap_due'],
                'scrap_given' => $openingBalance['scrap_given'],
                'balance' => $openingBalance['balance'],
                'vehicle_number' => null,
                'description' => 'Opening Balance as of ' . date('d-m-Y', strtotime($transactionDateFrom)),
                'item_id' => $itemId,
                'item_name' => $item ? $item->name : 'All Items',
                'item_code' => $item ? $item->item_code : null,
                'category_id' => null,
                'category_name' => null,
                'remark' => 'Opening Balance',
            ];

        $summary['scrap_due'] = $openingBalance['scrap_due'];  
        $summary['scrap_given'] = $openingBalance['scrap_given'];


        // Process voucher transactions
        foreach ($voucherMetas as $voucherMeta) {
            $voucher = $voucherMeta->voucher;
            $scrapValue = $this->calculateScrapValue($voucherMeta, $userSettings);
            
            // Update counters based on remark
            $remark = strtolower($voucherMeta->remark ?? '');
            if ($remark === 'mr' && $scrap_inhouse_mr === 'yes') {
                $summary['transaction_mr']++;
                $summary['transaction_mr_qty'] += $voucherMeta->item_quantity;
            } elseif ($remark === 'cr' && $scrap_inhouse_cr === 'yes') {
                $summary['transaction_cr']++;
                $summary['transaction_cr_qty'] += $voucherMeta->item_quantity;
            } elseif ($remark === 'bh' && $scrap_inhouse_bh === 'yes') {
                $summary['transaction_bh']++;
                $summary['transaction_bh_qty'] += $voucherMeta->item_quantity;
            } elseif ($remark === 'ok') {
                $summary['transaction_ok']++;
                $summary['transaction_ok_qty'] += $voucherMeta->item_quantity;
            } elseif ($remark === 'as-it-is') {
                $summary['transaction_asitis']++;
                $summary['transaction_asitis_qty'] += $voucherMeta->item_quantity;
            }

            $summary['scrap_due'] += $scrapValue;
            $summary['transaction_count']++;

            // Check if transaction should be shown based on remark and user settings
            $showTransaction = false;
            $remark = strtolower($voucherMeta->remark ?? ''); // Normalize remark to lowercase
            $quantity = $voucherMeta->item_quantity ?? 0;

            
            // Determine if transaction should be shown based on remark and user settings
            switch ($remark) {
                case 'mr':
                    $showTransaction = ($scrap_inhouse_mr === 'yes');
                    break;
                case 'cr':
                    $showTransaction = ($scrap_inhouse_cr === 'yes');
                    break;
                case 'bh':
                    $showTransaction = ($scrap_inhouse_bh === 'yes');
                    break;
                case 'ok':
                    $showTransaction = true; // Always show OK transactions
                    break;
                case 'as-it-is':
                    $showTransaction = ($quantity > 0); // Show only if quantity exists
                    break;
                default:
                    $showTransaction = true; // For any other remarks, show by default
            }



            if ($showTransaction) {
                $combinedRecords[] = [
                    'id' => $voucherMeta->id,
                    'date' => $voucher->transaction_date,
                    'voucher_type' => 'outward',
                    'voucher_no' => $voucher->voucher_no,
                    'party_id' => $voucher->party_id,
                    'party_name' => $voucher->party ? $voucher->party->name : null,
                    'quantity' => $voucherMeta->item_quantity,
                    'scrap_due' => $scrapValue,
                    'scrap_given' => 0, // No scrap given for outward transactions
                    'balance' => $scrapValue, // Initial balance is equal to scrap_due
                    'vehicle_number' => $voucher->vehicle_number,
                    'description' => $voucher->description,
                    'item_id' => $voucherMeta->item_id,
                    'item_name' => $voucherMeta->item ? $voucherMeta->item->name : null,
                    'item_code' => $voucherMeta->item ? $voucherMeta->item->item_code : null,
                    'category_id' => $voucherMeta->category_id,
                    'category_name' => $voucherMeta->category ? $voucherMeta->category->name : null,
                    'remark' => $voucherMeta->remark,
                ];
            }
        }

        // Process scrap transactions
        foreach ($scrapTransactions as $scrapTransaction) {
            $summary['scrap_given'] += $scrapTransaction->scrap_weight;
            $summary['transaction_count']++;
            $summary['scrap_transaction_count']++;
            $combinedRecords[] = [
                'id' => $scrapTransaction->id,
                'date' => $scrapTransaction->date,
                'voucher_type' => 'return scrap',
                'voucher_no' => $scrapTransaction->voucher_number,
                'party_id' => $scrapTransaction->party_id,
                'party_name' => $this->getPartyName($scrapTransaction->party_id), // You'll need to implement this method
                'quantity' => null, // Not applicable for scrap transactions
                'scrap_due' => 0, // No scrap due for return transactions
                'scrap_given' => $scrapTransaction->scrap_weight,
                'balance' => -$scrapTransaction->scrap_weight, // Negative balance for returned scrap
                'vehicle_number' => $scrapTransaction->vehical_number,
                'description' => $scrapTransaction->description,
                'item_id' => null, // Not applicable for scrap transactions
                'item_name' => null,
                'item_code' => null,
                'category_id' => null,
                'category_name' => null,
                'remark' => "Scrap returned",
            ];
        }

        // Sort combined records by date
        usort($combinedRecords, function($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });

        // Calculate running balance
        $runningBalance = $openingBalance['balance']; 
        foreach ($combinedRecords as &$record) {
            if ($record['id'] === 'opening-balance') {
                continue;
            }
            $runningBalance += $record['scrap_due'] - $record['scrap_given'];
            $record['balance'] = $runningBalance;
           
        }
        $summary['scrap_balance'] = $record['balance'] ;
        $summary['transaction_count'] = $summary['transaction_mr'] +$summary['transaction_cr'] +$summary['transaction_bh']+$summary['transaction_ok']+$summary['transaction_asitis']+ $summary['scrap_transaction_count'];

        
        // Return response with all records
        return response()->json([
            'status' => 200,
            'message' => 'Scrap records retrieved successfully',
            'records' => $combinedRecords,
            'summary' => $summary,
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
                'party' => $party ? [
                    'id' => $party->id,
                    'name' => $party->name,
                    'address_line_1' => $party->address1,
                    'address_line_2' => $party->address2,
                    'city' => $party->city,
                    'state' => $party->state->title,
                    'pincode' => $party->pincode,
                    'gst_number' => $party->gst_number,
                ] : null,
                'item' => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'code' => $item->item_code,
                    'hsn' => $item->hsn,
                    'description' => $item->description,
                ],
                'date_range' => [
                    'from' => $transactionDateFrom,
                    'to' => $transactionDateTo,
                ],
                'total_count' =>  $summary['transaction_count'],
                'userSettings'=>$userSettings
        ]);
    }

    public function downloadscrapreturnreportpdf(Request $request)
    {
        $response = $this->checkPermission('Scrap-Return-Reports-Download');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        $search = $request->input('search');
        
        // Fetch filter parameters
        $partyId = $request->input('party_id');
        $itemId = $request->input('item_id');
        $categoryId = $request->input('category_id');
        $voucherId = $request->input('voucher_id');
        $transactionDateFrom = $request->input('transaction_date_from');
        $transactionDateTo = $request->input('transaction_date_to');

        // Date validation logic
        if (($transactionDateFrom && !$transactionDateTo) || (!$transactionDateFrom && $transactionDateTo)) {
            return response()->json([
                'status' => 422,
                'message' => 'Both start and end dates must be provided',
            ], 200);
        }

        // If dates are not provided, use default range (current financial year)
        if (!$transactionDateFrom || !$transactionDateTo) {
            // Determine current financial year dates
            $currentMonth = date('m');
            $currentYear = date('Y');
            
            if ($currentMonth < 4) {
                // Current FY is previous year April to current year March
                $transactionDateFrom = ($currentYear - 1) . '-04-01';
                $transactionDateTo = $currentYear . '-03-31';
            } else {
                // Current FY is current year April to next year March
                $transactionDateFrom = $currentYear . '-04-01';
                $transactionDateTo = ($currentYear + 1) . '-03-31';
            }
        } else {
            // Validate date range - should not exceed 365 days
            $fromDate = new \DateTime($transactionDateFrom);
            $toDate = new \DateTime($transactionDateTo);
            $daysDifference = $fromDate->diff($toDate)->days;
            
            if ($daysDifference > 365) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Date range cannot exceed 365 days',
                ], 200);
            }
        }


         // 1. Get active company details
        $company = Company::with('state')->where('id', $activeCompanyId)
                        ->where('tenant_id', $tenantId)
                        ->first();
        
        if (!$company) {
            return response()->json([
                'status' => 422,
                'message' => 'Company not found',
            ], 200);
        }
        
        // 2. Get party details if party_id is provided
        $party = null;
        if ($partyId) {
            $party = Party::with('state')->where('id', $partyId)
                        ->where('tenant_id', $tenantId)
                        ->where('company_id', $activeCompanyId)
                        ->first();
            
            if (!$party) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Party not found',
                ], 200);
            }
        }
        
        // 3. Get item details
        $item = Item::where('id', $itemId)
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $activeCompanyId)
                    ->when($partyId, function($query) use ($partyId) {
                        return $query->whereJsonContains('party_id', (int) $partyId);
                    })
                    ->first();
        
        if (!$item) {
            return response()->json([
                'status' => 422,
                'message' => 'Item not found',
            ], 200);
        }
        

        // Get user settings for scrap handling
        $userSettings = usersettings::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->whereIn('slug', ['scrap_inhouse_cr', 'scrap_inhouse_mr', 'scrap_inhouse_bh','include_values_in_scrap_return'])
            ->pluck('val', 'slug')
            ->toArray();

         // Ensure user settings are available for each condition
        $scrap_inhouse_cr = $userSettings['scrap_inhouse_cr'] ?? 'no';
        $scrap_inhouse_mr = $userSettings['scrap_inhouse_mr'] ?? 'no';
        $scrap_inhouse_bh = $userSettings['scrap_inhouse_bh'] ?? 'no';

      


        // Start with the VoucherMeta query to get all outward transactions
        $voucherMetaQuery = VoucherMeta::with(['voucher.party', 'category', 'item'])
            ->whereHas('voucher', function ($q) use ($tenantId, $activeCompanyId) {
                $q->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->where('transaction_type', 'outward');
            })
            ->where('remark', '!=', 'as-it-is') 
            ->join('voucher', 'voucher_meta.voucher_id', '=', 'voucher.id')
            ->orderBy('voucher.transaction_date', 'asc')
            ->select('voucher_meta.*');


        // Apply search and filters to voucher meta
        $this->applyFilters($voucherMetaQuery, $search, $partyId, $itemId, $categoryId, $voucherId, $transactionDateFrom, $transactionDateTo);

        // Execute voucher meta query
        $voucherMetas = $voucherMetaQuery->get();

        // Start with the ScrapTransaction query to get all scrap transactions
        $scrapTransactionsQuery = DB::table('scrap_transactions')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->whereIn('scrap_type',['outward', 'adjustment'])
            ->orderBy('date', 'asc');

        // Apply date filters to scrap transactions
        if ($transactionDateFrom && $transactionDateTo) {
            $scrapTransactionsQuery->whereBetween('date', [$transactionDateFrom, $transactionDateTo]);
        } elseif ($transactionDateFrom) {
            $scrapTransactionsQuery->where('date', '>=', $transactionDateFrom);
        } elseif ($transactionDateTo) {
            $scrapTransactionsQuery->where('date', '<=', $transactionDateTo);
        }

        // Apply party filter to scrap transactions
        if ($partyId) {
            $scrapTransactionsQuery->where('party_id', $partyId);
        }

        

        // Execute scrap transactions query
        $scrapTransactions = $scrapTransactionsQuery->get();

        // Initialize combined records and counters
        $combinedRecords = [];
        $summary = [
            'scrap_due' => 0,
            'scrap_given' => 0,
            'scrap_balance' =>0,
            'scrap_transaction_count' =>0,
            'transaction_count' => 0,
            'transaction_mr' => 0,
            'transaction_cr' => 0,
            'transaction_bh' => 0,
            'transaction_ok' => 0,
            'transaction_asitis' => 0,
            'transaction_mr_qty'=> 0,
            'transaction_cr_qty'=> 0,
            'transaction_bh_qty'=> 0,
            'transaction_ok_qty'=> 0,
            'transaction_asitis_qty'=> 0,
        ];

        // Calculate opening balance as of transaction date from
        $openingBalance = $this->calculateOpeningBalance($tenantId, $activeCompanyId, $partyId, $itemId, $transactionDateFrom);

        // Add opening balance entry at the beginning of combined records
            $combinedRecords[] = [
                'id' => 'opening-balance',
                'date' => $transactionDateFrom, // Start date of the report
                'voucher_type' => 'opening',
                'voucher_no' => '-',
                'party_id' => $partyId,
                'party_name' => $party ? $party->name : 'All Parties',
                'quantity' => null,
                'scrap_due' => $openingBalance['scrap_due'],
                'scrap_given' => $openingBalance['scrap_given'],
                'balance' => $openingBalance['balance'],
                'vehicle_number' => null,
                'description' => 'Opening Balance as of ' . date('d-m-Y', strtotime($transactionDateFrom)),
                'item_id' => $itemId,
                'item_name' => $item ? $item->name : 'All Items',
                'item_code' => $item ? $item->item_code : null,
                'category_id' => null,
                'category_name' => null,
                'remark' => 'Opening Balance',
            ];

        $summary['scrap_due'] = $openingBalance['scrap_due'];  
        $summary['scrap_given'] = $openingBalance['scrap_given'];


        // Process voucher transactions
        foreach ($voucherMetas as $voucherMeta) {
            $voucher = $voucherMeta->voucher;
            $scrapValue = $this->calculateScrapValue($voucherMeta, $userSettings);
            
            // Update counters based on remark
            $remark = strtolower($voucherMeta->remark ?? '');
            if ($remark === 'mr' && $scrap_inhouse_mr === 'yes') {
                $summary['transaction_mr']++;
                $summary['transaction_mr_qty'] += $voucherMeta->item_quantity;
            } elseif ($remark === 'cr' && $scrap_inhouse_cr === 'yes') {
                $summary['transaction_cr']++;
                $summary['transaction_cr_qty'] += $voucherMeta->item_quantity;
            } elseif ($remark === 'bh' && $scrap_inhouse_bh === 'yes') {
                $summary['transaction_bh']++;
                $summary['transaction_bh_qty'] += $voucherMeta->item_quantity;
            } elseif ($remark === 'ok') {
                $summary['transaction_ok']++;
                $summary['transaction_ok_qty'] += $voucherMeta->item_quantity;
            } elseif ($remark === 'as-it-is') {
                $summary['transaction_asitis']++;
                $summary['transaction_asitis_qty'] += $voucherMeta->item_quantity;
            }

            $summary['scrap_due'] += $scrapValue;
            $summary['transaction_count']++;

            // Check if transaction should be shown based on remark and user settings
            $showTransaction = false;
            $remark = strtolower($voucherMeta->remark ?? ''); // Normalize remark to lowercase
            $quantity = $voucherMeta->item_quantity ?? 0;

            
            // Determine if transaction should be shown based on remark and user settings
            switch ($remark) {
                case 'mr':
                    $showTransaction = ($scrap_inhouse_mr === 'yes');
                    break;
                case 'cr':
                    $showTransaction = ($scrap_inhouse_cr === 'yes');
                    break;
                case 'bh':
                    $showTransaction = ($scrap_inhouse_bh === 'yes');
                    break;
                case 'ok':
                    $showTransaction = true; // Always show OK transactions
                    break;
                case 'as-it-is':
                    $showTransaction = ($quantity > 0); // Show only if quantity exists
                    break;
                default:
                    $showTransaction = true; // For any other remarks, show by default
            }



            if ($showTransaction) {
                $combinedRecords[] = [
                    'id' => $voucherMeta->id,
                    'date' => $voucher->transaction_date,
                    'voucher_type' => 'outward',
                    'voucher_no' => $voucher->voucher_no,
                    'party_id' => $voucher->party_id,
                    'party_name' => $voucher->party ? $voucher->party->name : null,
                    'quantity' => $voucherMeta->item_quantity,
                    'scrap_due' => $scrapValue,
                    'scrap_given' => 0, // No scrap given for outward transactions
                    'balance' => $scrapValue, // Initial balance is equal to scrap_due
                    'vehicle_number' => $voucher->vehicle_number,
                    'description' => $voucher->description,
                    'item_id' => $voucherMeta->item_id,
                    'item_name' => $voucherMeta->item ? $voucherMeta->item->name : null,
                    'item_code' => $voucherMeta->item ? $voucherMeta->item->item_code : null,
                    'category_id' => $voucherMeta->category_id,
                    'category_name' => $voucherMeta->category ? $voucherMeta->category->name : null,
                    'remark' => $voucherMeta->remark,
                ];
            }
        }

        // Process scrap transactions
        foreach ($scrapTransactions as $scrapTransaction) {
            $summary['scrap_given'] += $scrapTransaction->scrap_weight;
            $summary['transaction_count']++;
            $summary['scrap_transaction_count']++;
            $combinedRecords[] = [
                'id' => $scrapTransaction->id,
                'date' => $scrapTransaction->date,
                'voucher_type' => 'return scrap',
                'voucher_no' => $scrapTransaction->voucher_number,
                'party_id' => $scrapTransaction->party_id,
                'party_name' => $this->getPartyName($scrapTransaction->party_id), // You'll need to implement this method
                'quantity' => null, // Not applicable for scrap transactions
                'scrap_due' => 0, // No scrap due for return transactions
                'scrap_given' => $scrapTransaction->scrap_weight,
                'balance' => -$scrapTransaction->scrap_weight, // Negative balance for returned scrap
                'vehicle_number' => $scrapTransaction->vehical_number,
                'description' => $scrapTransaction->description,
                'item_id' => null, // Not applicable for scrap transactions
                'item_name' => null,
                'item_code' => null,
                'category_id' => null,
                'category_name' => null,
                'remark' => "Scrap returned",
            ];
        }

        // Sort combined records by date
        usort($combinedRecords, function($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });

        // Calculate running balance
        $runningBalance = $openingBalance['balance']; 
        foreach ($combinedRecords as &$record) {
            if ($record['id'] === 'opening-balance') {
                continue;
            }
            $runningBalance += $record['scrap_due'] - $record['scrap_given'];
            $record['balance'] = $runningBalance;
           
        }
        $summary['scrap_balance'] = $record['balance'] ;
        $summary['transaction_count'] = $summary['transaction_mr'] +$summary['transaction_cr'] +$summary['transaction_bh']+$summary['transaction_ok']+$summary['transaction_asitis']+ $summary['scrap_transaction_count'];

        
       $data= ['records' => $combinedRecords,
                'summary' => $summary,
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
                    'party' => $party ? [
                        'id' => $party->id,
                        'name' => $party->name,
                        'address_line_1' => $party->address1,
                        'address_line_2' => $party->address2,
                        'city' => $party->city,
                        'state' => $party->state->title,
                        'pincode' => $party->pincode,
                        'gst_number' => $party->gst_number,
                    ] : null,
                    'item' => [
                        'id' => $item->id,
                        'name' => $item->name,
                        'code' => $item->item_code,
                        'hsn' => $item->hsn,
                        'description' => $item->description,
                    ],
                    'date_range' => [
                        'from' => $transactionDateFrom,
                        'to' => $transactionDateTo,
                    ],
                    'total_count' =>  $summary['transaction_count'],
                    'userSettings'=>$userSettings
                ];

        // Configure Dompdf
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans'); // Important for special characters

        // Instantiate Dompdf
        $pdf = new Dompdf($options);

        // Load the HTML from the Blade view
        $view = view('pdf.scrapreturnReport', ['data' => $data])->render();
        $pdf->loadHtml($view, 'UTF-8');

        // Set paper size and orientation
        $pdf->setPaper('A4', 'portrait');

        // Render the PDF
        $pdf->render();

        // Generate a dynamic filename
        $filename = "scrapreturnReport_" . ($data['item']['code'] ?? 'item') . "_" . date('Ymd') . ".pdf";
        
        // Stream the PDF to the browser (inline view)
        return $pdf->stream($filename);  
    }

    public function scrapreceivablereportindex(Request $request)
    {
        $response = $this->checkPermission('Scrap-Receivable-Reports-Menu');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        // Get per_page parameter or default to 10
        $perPage = $request->input('per_page', 10);
        // Get page parameter or default to 1
        $page = $request->input('page', 1);
        $search = $request->input('search');
        
        // Fetch filter parameters
        $partyId = $request->input('party_id');
        $itemId = $request->input('item_id');
        $categoryId = $request->input('category_id');
        $voucherId = $request->input('voucher_id');
        $transactionDateFrom = $request->input('transaction_date_from');
        $transactionDateTo = $request->input('transaction_date_to');

        // Get user settings for scrap handling
        $userSettings = usersettings::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->whereIn('slug', ['scrap_outsourcing_cr', 'scrap_outsourcing_mr', 'scrap_outsourcing_bh'])
            ->pluck('val', 'slug')
            ->toArray();

        // Start with the VoucherMeta query to get all outward transactions
        $voucherMetaQuery = VoucherMeta::with(['voucher.party', 'category', 'item'])
            ->whereHas('voucher', function ($q) use ($tenantId, $activeCompanyId) {
                $q->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->where('transaction_type', 's_inward');
            })
            ->join('voucher', 'voucher_meta.voucher_id', '=', 'voucher.id')
            ->orderBy('voucher.transaction_date', 'desc')
            ->select('voucher_meta.*');


        // Apply search and filters to voucher meta
        $this->applyFilters($voucherMetaQuery, $search, $partyId, $itemId, $categoryId, $voucherId, $transactionDateFrom, $transactionDateTo);

        // Execute voucher meta query without pagination to combine with scrap transactions
        $voucherMetas = $voucherMetaQuery->get();

        // Start with the ScrapTransaction query to get all scrap transactions
        $scrapTransactionsQuery = DB::table('scrap_transactions')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->whereIn('scrap_type',['inward', 'adjustment'])
            ->orderBy('date', 'asc');

        // Apply date filters to scrap transactions
        if ($transactionDateFrom && $transactionDateTo) {
            $scrapTransactionsQuery->whereBetween('date', [$transactionDateFrom, $transactionDateTo]);
        } elseif ($transactionDateFrom) {
            $scrapTransactionsQuery->where('date', '>=', $transactionDateFrom);
        } elseif ($transactionDateTo) {
            $scrapTransactionsQuery->where('date', '<=', $transactionDateTo);
        }

        // Apply party filter to scrap transactions
        if ($partyId) {
            $scrapTransactionsQuery->where('party_id', $partyId);
        }

        // Apply search filter to scrap transactions
        if ($search) {
            $scrapTransactionsQuery->where(function ($q) use ($search) {
                $q->where('voucher_number', 'like', "%{$search}%")
                ->orWhere('vehical_number', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Execute scrap transactions query
        $scrapTransactions = $scrapTransactionsQuery->get();

        // Initialize combined records and counters
        $combinedRecords = [];
        $summary = [
            'scrap_receivable' => 0,
            'scrap_taken' => 0,
            'scrap_balance' =>0,
            'transaction_count' => 0,
            'transaction_mr' => 0,
            'transaction_cr' => 0,
            'transaction_bh' => 0,
            'transaction_ok' => 0,
            'transaction_asitis' => 0,
            'transaction_mr_qty'=> 0,
            'transaction_cr_qty'=> 0,
            'transaction_bh_qty'=> 0,
            'transaction_ok_qty'=> 0,
            'transaction_asitis_qty'=> 0,
        ];

      

        // Process voucher transactions
        foreach ($voucherMetas as $voucherMeta) {
            $voucher = $voucherMeta->voucher;
            $scrapValue = $this->calculateScrapValueinward($voucherMeta, $userSettings);
            
            // Update counters based on remark
            $remark = strtolower($voucherMeta->remark ?? '');
            switch ($remark) {
                case 'mr':
                    $summary['transaction_mr']++;
                    $summary['transaction_mr_qty'] += $voucherMeta->item_quantity;
                    break;
                case 'cr':
                    $summary['transaction_cr']++;
                    $summary['transaction_cr_qty'] += $voucherMeta->item_quantity;
                    break;
                case 'bh':
                    $summary['transaction_bh']++;
                    $summary['transaction_bh_qty'] += $voucherMeta->item_quantity;
                    break;
                case 'ok':
                    $summary['transaction_ok']++;
                    $summary['transaction_ok_qty'] += $voucherMeta->item_quantity;
                    break;
                case 'as-it-is':
                    $summary['transaction_asitis']++;
                    $summary['transaction_asitis_qty'] += $voucherMeta->item_quantity;
                    break;
            }

            $summary['scrap_receivable'] += $scrapValue;
            $summary['transaction_count']++;

            $combinedRecords[] = [
                'id' => $voucherMeta->id,
                'date' => $voucher->transaction_date,
                'voucher_type' => 'subcontract inward',
                'voucher_no' => $voucher->voucher_no,
                'party_id' => $voucher->party_id,
                'party_name' => $voucher->party ? $voucher->party->name : null,
                'quantity' => $voucherMeta->item_quantity,
                'scrap_receivable' => $scrapValue,
                'scrap_taken' => 0, // No scrap given for outward transactions
                'balance' => $scrapValue, // Initial balance is equal to scrap_receivable
                'vehicle_number' => $voucher->vehicle_number,
                'description' => $voucher->description,
                'item_id' => $voucherMeta->item_id,
                'item_name' => $voucherMeta->item ? $voucherMeta->item->name : null,
                'item_code' => $voucherMeta->item ? $voucherMeta->item->item_code : null,
                'category_id' => $voucherMeta->category_id,
                'category_name' => $voucherMeta->category ? $voucherMeta->category->name : null,
                'remark' => $voucherMeta->remark,
            ];
        }

        // Process scrap transactions
        foreach ($scrapTransactions as $scrapTransaction) {
            $summary['scrap_taken'] += $scrapTransaction->scrap_weight;
            $summary['transaction_count']++;

            $combinedRecords[] = [
                'id' => $scrapTransaction->id,
                'date' => $scrapTransaction->date,
                'voucher_type' => 'return scrap',
                'voucher_no' => $scrapTransaction->voucher_number,
                'party_id' => $scrapTransaction->party_id,
                'party_name' => $this->getPartyName($scrapTransaction->party_id), // You'll need to implement this method
                'quantity' => null, // Not applicable for scrap transactions
                'scrap_receivable' => 0, // No scrap due for return transactions
                'scrap_taken' => $scrapTransaction->scrap_weight,
                'balance' => -$scrapTransaction->scrap_weight, // Negative balance for returned scrap
                'vehicle_number' => $scrapTransaction->vehical_number,
                'description' => $scrapTransaction->description,
                'item_id' => null, // Not applicable for scrap transactions
                'item_name' => null,
                'item_code' => null,
                'category_id' => null,
                'category_name' => null,
                'remark' => "Scrap received",
            ];
        }

        // Sort combined records by date
        usort($combinedRecords, function($a, $b) {
               return strtotime($b['date']) - strtotime($a['date']);
        });

        // Calculate running balance
        $runningBalance = 0;
        foreach ($combinedRecords as &$record) {
            $runningBalance += $record['scrap_receivable'] - $record['scrap_taken'];
            $record['balance'] = $runningBalance;
             $summary['scrap_balance'] = $record['balance'] ;
        }

        // Paginate the combined records manually
        $total = count($combinedRecords);
        $lastPage = ceil($total / $perPage);
        $offset = ($page - 1) * $perPage;
        $paginatedRecords = array_slice($combinedRecords, $offset, $perPage);

        // Return response with pagination metadata
        return response()->json([
            'status' => 200,
            'message' => 'Scrap records retrieved successfully',
            'records' => $paginatedRecords,
            'summary' => $summary,
            'pagination' => [
                'current_page' => (int)$page,
                'total_count' => $total,
                'per_page' => (int)$perPage,
                'last_page' => $lastPage,
            ],
        ]);
    }


      /**
     * Calculate scrap value based on voucher meta and user settings
     */
    private function calculateScrapValueinward($voucherMeta, $userSettings)
    {
        $scrapValue = 0;
        $remark = strtolower($voucherMeta->remark ?? ''); // Normalize remark to lowercase
        $quantity = $voucherMeta->item_quantity ?? 0;
        // Ensure user settings are available for each condition
        $scrap_outsourcing_cr = $userSettings['scrap_outsourcing_cr'] ?? 'no';
        $scrap_outsourcing_mr = $userSettings['scrap_outsourcing_mr'] ?? 'no';
        $scrap_outsourcing_bh = $userSettings['scrap_outsourcing_bh'] ?? 'no';
        
        // Determine if scrap_wt should be used based on remark and user settings
        if ($remark === 'mr' && $scrap_outsourcing_mr === 'yes') {
            $scrapValue = ($voucherMeta->scrap_wt * $quantity) ?? 0;
        } elseif ($remark === 'cr' && $scrap_outsourcing_cr === 'yes') {
            $scrapValue = ($voucherMeta->scrap_wt * $quantity) ?? 0;
        } elseif ($remark === 'bh' && $scrap_outsourcing_bh === 'yes') {
            $scrapValue = ($voucherMeta->scrap_wt * $quantity) ?? 0;
        } elseif ($remark === 'ok') {
            $scrapValue = ($voucherMeta->scrap_wt * $quantity) ?? 0;
        } elseif ($remark === 'as-it-is') {
            $scrapValue = 0;
        }
        
        return $scrapValue;
    }

     // Calculate opening balance (transactions before the start date)
    private function calculateOpeningBalancesubcontract($tenantId, $activeCompanyId, $partyId, $itemId, $transactionDateFrom)
    {
        $openingBalance = [
            'scrap_due' => 0,
            'scrap_given' => 0,
            'balance' => 0
        ];
        
        // Get user settings for scrap handling
        $userSettings = usersettings::where('tenant_id', $tenantId)
            ->where('user_id', auth()->id())
            ->whereIn('slug', ['scrap_outsourcing_cr', 'scrap_outsourcing_mr', 'scrap_outsourcing_bh'])
            ->pluck('val', 'slug')
            ->toArray();
        
        // Calculate scrap due from outward transactions before start date
        $voucherMetaQuery = VoucherMeta::with(['voucher.party', 'category', 'item'])
            ->whereHas('voucher', function ($q) use ($tenantId, $activeCompanyId, $transactionDateFrom, $partyId) {
                $q->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->where('transaction_type', 's_inward')
                ->where('transaction_date', '<', $transactionDateFrom);
                
                if ($partyId) {
                    $q->where('party_id', $partyId);
                }
            });
        
        if ($itemId) {
            $voucherMetaQuery->where('item_id', $itemId);
        }
        
        $voucherMetas = $voucherMetaQuery->get();
        
        foreach ($voucherMetas as $voucherMeta) {
            $scrapValue = $this->calculateScrapValueinward($voucherMeta, $userSettings);
            $openingBalance['scrap_due'] += $scrapValue;
        }
        
        // Calculate scrap given from scrap transactions before start date
        $scrapTransactionsQuery = DB::table('scrap_transactions')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->whereIn('scrap_type', ['inward', 'adjustment'])
            ->where('date', '<', $transactionDateFrom);
        
        if ($partyId) {
            $scrapTransactionsQuery->where('party_id', $partyId);
        }
        
        $scrapTransactions = $scrapTransactionsQuery->get();
        
        foreach ($scrapTransactions as $scrapTransaction) {
            $openingBalance['scrap_given'] += $scrapTransaction->scrap_weight;
        }
        
        // Calculate net opening balance
        $openingBalance['balance'] = $openingBalance['scrap_due'] - $openingBalance['scrap_given'];
        
        return $openingBalance;
    }

   
    public function scrapreceivablereportpdf(Request $request)
    {
        $response = $this->checkPermission('Scrap-Receivable-Reports-Download');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        $search = $request->input('search');
        
        // Fetch filter parameters
        $partyId = $request->input('party_id');
        $itemId = $request->input('item_id');
        $categoryId = $request->input('category_id');
        $voucherId = $request->input('voucher_id');
        $transactionDateFrom = $request->input('transaction_date_from');
        $transactionDateTo = $request->input('transaction_date_to');

        // Date validation logic
        if (($transactionDateFrom && !$transactionDateTo) || (!$transactionDateFrom && $transactionDateTo)) {
            return response()->json([
                'status' => 422,
                'message' => 'Both start and end dates must be provided',
            ], 200);
        }

        // If dates are not provided, use default range (current financial year)
        if (!$transactionDateFrom || !$transactionDateTo) {
            // Determine current financial year dates
            $currentMonth = date('m');
            $currentYear = date('Y');
            
            if ($currentMonth < 4) {
                // Current FY is previous year April to current year March
                $transactionDateFrom = ($currentYear - 1) . '-04-01';
                $transactionDateTo = $currentYear . '-03-31';
            } else {
                // Current FY is current year April to next year March
                $transactionDateFrom = $currentYear . '-04-01';
                $transactionDateTo = ($currentYear + 1) . '-03-31';
            }
        } else {
            // Validate date range - should not exceed 365 days
            $fromDate = new \DateTime($transactionDateFrom);
            $toDate = new \DateTime($transactionDateTo);
            $daysDifference = $fromDate->diff($toDate)->days;
            
            if ($daysDifference > 365) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Date range cannot exceed 365 days',
                ], 200);
            }
        }

         // 1. Get active company details
        $company = Company::with('state')->where('id', $activeCompanyId)
                        ->where('tenant_id', $tenantId)
                        ->first();
        
        if (!$company) {
            return response()->json([
                'status' => 422,
                'message' => 'Company not found',
            ], 200);
        }
        
        // 2. Get party details if party_id is provided
        $party = null;
        if ($partyId) {
            $party = Party::with('state')->where('id', $partyId)
                        ->where('tenant_id', $tenantId)
                        ->where('company_id', $activeCompanyId)
                        ->first();
            
            if (!$party) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Party not found',
                ], 200);
            }
        }
        
        // 3. Get item details
        $item = Item::where('id', $itemId)
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $activeCompanyId)
                    ->when($partyId, function($query) use ($partyId) {
                        return $query->whereJsonContains('party_id', (int) $partyId);
                    })
                    ->first();
        
        if (!$item) {
            return response()->json([
                'status' => 422,
                'message' => 'Item not found',
            ], 200);
        }

        // Get user settings for scrap handling
        $userSettings = usersettings::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->whereIn('slug', ['scrap_outsourcing_cr', 'scrap_outsourcing_mr', 'scrap_outsourcing_bh','include_values_in_scrap_return'])
            ->pluck('val', 'slug')
            ->toArray();

        // Ensure user settings are available for each condition
            $scrap_outsourcing_cr = $userSettings['scrap_outsourcing_cr'] ?? 'no';
            $scrap_outsourcing_mr = $userSettings['scrap_outsourcing_mr'] ?? 'no';
            $scrap_outsourcing_bh = $userSettings['scrap_outsourcing_bh'] ?? 'no';   

        // Start with the VoucherMeta query to get all outward transactions
        $voucherMetaQuery = VoucherMeta::with(['voucher.party', 'category', 'item'])
            ->whereHas('voucher', function ($q) use ($tenantId, $activeCompanyId) {
                $q->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->where('transaction_type', 's_inward');
            })
            ->where('remark', '!=', 'as-it-is') 
            ->join('voucher', 'voucher_meta.voucher_id', '=', 'voucher.id')
            ->orderBy('voucher.transaction_date', 'asc')
            ->select('voucher_meta.*');

        // Apply search and filters to voucher meta
        $this->applyFilters($voucherMetaQuery, $search, $partyId, $itemId, $categoryId, $voucherId, $transactionDateFrom, $transactionDateTo);

        // Execute voucher meta query
        $voucherMetas = $voucherMetaQuery->get();

        // Start with the ScrapTransaction query to get all scrap transactions
        $scrapTransactionsQuery = DB::table('scrap_transactions')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->whereIn('scrap_type',['inward', 'adjustment'])
            ->orderBy('date', 'asc');

        // Apply date filters to scrap transactions
        if ($transactionDateFrom && $transactionDateTo) {
            $scrapTransactionsQuery->whereBetween('date', [$transactionDateFrom, $transactionDateTo]);
        } elseif ($transactionDateFrom) {
            $scrapTransactionsQuery->where('date', '>=', $transactionDateFrom);
        } elseif ($transactionDateTo) {
            $scrapTransactionsQuery->where('date', '<=', $transactionDateTo);
        }

        // Apply party filter to scrap transactions
        if ($partyId) {
            $scrapTransactionsQuery->where('party_id', $partyId);
        }

        

        // Execute scrap transactions query
        $scrapTransactions = $scrapTransactionsQuery->get();

        // Initialize combined records and counters
        $combinedRecords = [];
        $summary = [
            'scrap_receivable' => 0,
            'scrap_taken' => 0,
            'scrap_balance' =>0,
            'scrap_transaction_count' =>0,
            'transaction_count' => 0,
            'transaction_mr' => 0,
            'transaction_cr' => 0,
            'transaction_bh' => 0,
            'transaction_ok' => 0,
            'transaction_asitis' => 0,
            'transaction_mr_qty'=> 0,
            'transaction_cr_qty'=> 0,
            'transaction_bh_qty'=> 0,
            'transaction_ok_qty'=> 0,
            'transaction_asitis_qty'=> 0,
        ];

        // Calculate opening balance as of transaction date from
        $openingBalance = $this->calculateOpeningBalancesubcontract($tenantId, $activeCompanyId, $partyId, $itemId, $transactionDateFrom);

        // Add opening balance entry at the beginning of combined records
            $combinedRecords[] = [
                'id' => 'opening-balance',
                'date' => $transactionDateFrom, // Start date of the report
                'voucher_type' => 'opening',
                'voucher_no' => '-',
                'party_id' => $partyId,
                'party_name' => $party ? $party->name : 'All Parties',
                'quantity' => null,
                'scrap_receivable' => $openingBalance['scrap_due'],
                'scrap_taken' => $openingBalance['scrap_given'],
                'balance' => $openingBalance['balance'],
                'vehicle_number' => null,
                'description' => 'Opening Balance as of ' . date('d-m-Y', strtotime($transactionDateFrom)),
                'item_id' => $itemId,
                'item_name' => $item ? $item->name : 'All Items',
                'item_code' => $item ? $item->item_code : null,
                'category_id' => null,
                'category_name' => null,
                'remark' => 'Opening Balance',
            ];

        $summary['scrap_receivable'] = $openingBalance['scrap_due'];  
        $summary['scrap_taken'] = $openingBalance['scrap_given'];

        // Process voucher transactions
        foreach ($voucherMetas as $voucherMeta) {
            $voucher = $voucherMeta->voucher;
            $scrapValue = $this->calculateScrapValueinward($voucherMeta, $userSettings);
            
            // Update counters based on remark
            $remark = strtolower($voucherMeta->remark ?? '');
             if ($remark === 'mr' && $scrap_outsourcing_mr === 'yes') {
                $summary['transaction_mr']++;
                $summary['transaction_mr_qty'] += $voucherMeta->item_quantity;
            } elseif ($remark === 'cr' && $scrap_outsourcing_cr === 'yes') {
                $summary['transaction_cr']++;
                $summary['transaction_cr_qty'] += $voucherMeta->item_quantity;
            } elseif ($remark === 'bh' && $scrap_outsourcing_bh === 'yes') {
                $summary['transaction_bh']++;
                $summary['transaction_bh_qty'] += $voucherMeta->item_quantity;
            } elseif ($remark === 'ok') {
                $summary['transaction_ok']++;
                $summary['transaction_ok_qty'] += $voucherMeta->item_quantity;
            } elseif ($remark === 'as-it-is') {
                $summary['transaction_asitis']++;
                $summary['transaction_asitis_qty'] += $voucherMeta->item_quantity;
            }

            $summary['scrap_receivable'] += $scrapValue;
            $summary['transaction_count']++;

            // Check if transaction should be shown based on remark and user settings
            $showTransaction = false;
            $remark = strtolower($voucherMeta->remark ?? ''); // Normalize remark to lowercase
            $quantity = $voucherMeta->item_quantity ?? 0;

            // Ensure user settings are available for each condition
            $scrap_outsourcing_cr = $userSettings['scrap_outsourcing_cr'] ?? 'no';
            $scrap_outsourcing_mr = $userSettings['scrap_outsourcing_mr'] ?? 'no';
            $scrap_outsourcing_bh = $userSettings['scrap_outsourcing_bh'] ?? 'no';

            // Determine if transaction should be shown based on remark and user settings
            switch ($remark) {
                case 'mr':
                    $showTransaction = ($scrap_outsourcing_mr === 'yes');
                    break;
                case 'cr':
                    $showTransaction = ($scrap_outsourcing_cr === 'yes');
                    break;
                case 'bh':
                    $showTransaction = ($scrap_outsourcing_bh === 'yes');
                    break;
                case 'ok':
                    $showTransaction = true; // Always show OK transactions
                    break;
                case 'as-it-is':
                    $showTransaction = ($quantity > 0); // Show only if quantity exists
                    break;
                default:
                    $showTransaction = true; // For any other remarks, show by default
            }

            if ($showTransaction) {

                $combinedRecords[] = [
                    'id' => $voucherMeta->id,
                    'date' => $voucher->transaction_date,
                    'voucher_type' => 'subcontract inward',
                    'voucher_no' => $voucher->voucher_no,
                    'party_id' => $voucher->party_id,
                    'party_name' => $voucher->party ? $voucher->party->name : null,
                    'quantity' => $voucherMeta->item_quantity,
                    'scrap_receivable' => $scrapValue,
                    'scrap_taken' => 0, // No scrap given for outward transactions
                    'balance' => $scrapValue, // Initial balance is equal to scrap_receivable
                    'vehicle_number' => $voucher->vehicle_number,
                    'description' => $voucher->description,
                    'item_id' => $voucherMeta->item_id,
                    'item_name' => $voucherMeta->item ? $voucherMeta->item->name : null,
                    'item_code' => $voucherMeta->item ? $voucherMeta->item->item_code : null,
                    'category_id' => $voucherMeta->category_id,
                    'category_name' => $voucherMeta->category ? $voucherMeta->category->name : null,
                    'remark' => $voucherMeta->remark,
                ];
            }
        }

        // Process scrap transactions
        foreach ($scrapTransactions as $scrapTransaction) {
            $summary['scrap_taken'] += $scrapTransaction->scrap_weight;
            $summary['transaction_count']++;
            $summary['scrap_transaction_count']++;
            $combinedRecords[] = [
                'id' => $scrapTransaction->id,
                'date' => $scrapTransaction->date,
                'voucher_type' => 'return scrap',
                'voucher_no' => $scrapTransaction->voucher_number,
                'party_id' => $scrapTransaction->party_id,
                'party_name' => $this->getPartyName($scrapTransaction->party_id), // You'll need to implement this method
                'quantity' => null, // Not applicable for scrap transactions
                'scrap_receivable' => 0, // No scrap due for return transactions
                'scrap_taken' => $scrapTransaction->scrap_weight,
                'balance' => -$scrapTransaction->scrap_weight, // Negative balance for returned scrap
                'vehicle_number' => $scrapTransaction->vehical_number,
                'description' => $scrapTransaction->description,
                'item_id' => null, // Not applicable for scrap transactions
                'item_name' => null,
                'item_code' => null,
                'category_id' => null,
                'category_name' => null,
                'remark' => "Scrap received",
            ];
        }

        // Sort combined records by date
        usort($combinedRecords, function($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });

        // Calculate running balance
       $runningBalance = $openingBalance['balance']; 
        foreach ($combinedRecords as &$record) {
             if ($record['id'] === 'opening-balance') {
                continue;
            }
            $runningBalance += $record['scrap_receivable'] - $record['scrap_taken'];
            $record['balance'] = $runningBalance;
            
        }

        $summary['scrap_balance'] = $record['balance'] ;
        $summary['transaction_count'] = $summary['transaction_mr'] +$summary['transaction_cr'] +$summary['transaction_bh']+$summary['transaction_ok']+$summary['transaction_asitis']+ $summary['scrap_transaction_count'];
        // Return response with all records
        return response()->json([
            'status' => 200,
            'message' => 'Scrap records retrieved successfully',
            'records' => $combinedRecords,
            'summary' => $summary,
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
                'party' => $party ? [
                    'id' => $party->id,
                    'name' => $party->name,
                    'address_line_1' => $party->address1,
                    'address_line_2' => $party->address2,
                    'city' => $party->city,
                    'state' => $party->state->title,
                    'pincode' => $party->pincode,
                    'gst_number' => $party->gst_number,
                ] : null,
                'item' => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'code' => $item->item_code,
                    'hsn' => $item->hsn,
                    'description' => $item->description,
                ],
                'date_range' => [
                    'from' => $transactionDateFrom,
                    'to' => $transactionDateTo,
                ],
                'total_count' => count($combinedRecords),
                'userSettings'=>$userSettings
        ]);
    }

    public function downloadscrapreceivablereportpdf(Request $request)
    {
        $response = $this->checkPermission('Scrap-Receivable-Reports-Download');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        $search = $request->input('search');
        
        // Fetch filter parameters
        $partyId = $request->input('party_id');
        $itemId = $request->input('item_id');
        $categoryId = $request->input('category_id');
        $voucherId = $request->input('voucher_id');
        $transactionDateFrom = $request->input('transaction_date_from');
        $transactionDateTo = $request->input('transaction_date_to');

        // Date validation logic
        if (($transactionDateFrom && !$transactionDateTo) || (!$transactionDateFrom && $transactionDateTo)) {
            return response()->json([
                'status' => 422,
                'message' => 'Both start and end dates must be provided',
            ], 200);
        }

        // If dates are not provided, use default range (current financial year)
        if (!$transactionDateFrom || !$transactionDateTo) {
            // Determine current financial year dates
            $currentMonth = date('m');
            $currentYear = date('Y');
            
            if ($currentMonth < 4) {
                // Current FY is previous year April to current year March
                $transactionDateFrom = ($currentYear - 1) . '-04-01';
                $transactionDateTo = $currentYear . '-03-31';
            } else {
                // Current FY is current year April to next year March
                $transactionDateFrom = $currentYear . '-04-01';
                $transactionDateTo = ($currentYear + 1) . '-03-31';
            }
        } else {
            // Validate date range - should not exceed 365 days
            $fromDate = new \DateTime($transactionDateFrom);
            $toDate = new \DateTime($transactionDateTo);
            $daysDifference = $fromDate->diff($toDate)->days;
            
            if ($daysDifference > 365) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Date range cannot exceed 365 days',
                ], 200);
            }
        }

         // 1. Get active company details
        $company = Company::with('state')->where('id', $activeCompanyId)
                        ->where('tenant_id', $tenantId)
                        ->first();
        
        if (!$company) {
            return response()->json([
                'status' => 422,
                'message' => 'Company not found',
            ], 200);
        }
        
        // 2. Get party details if party_id is provided
        $party = null;
        if ($partyId) {
            $party = Party::with('state')->where('id', $partyId)
                        ->where('tenant_id', $tenantId)
                        ->where('company_id', $activeCompanyId)
                        ->first();
            
            if (!$party) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Party not found',
                ], 200);
            }
        }
        
        // 3. Get item details
        $item = Item::where('id', $itemId)
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $activeCompanyId)
                    ->when($partyId, function($query) use ($partyId) {
                        return $query->whereJsonContains('party_id', (int) $partyId);
                    })
                    ->first();
        
        if (!$item) {
            return response()->json([
                'status' => 422,
                'message' => 'Item not found',
            ], 200);
        }

        // Get user settings for scrap handling
        $userSettings = usersettings::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->whereIn('slug', ['scrap_outsourcing_cr', 'scrap_outsourcing_mr', 'scrap_outsourcing_bh','include_values_in_scrap_return'])
            ->pluck('val', 'slug')
            ->toArray();

        // Ensure user settings are available for each condition
            $scrap_outsourcing_cr = $userSettings['scrap_outsourcing_cr'] ?? 'no';
            $scrap_outsourcing_mr = $userSettings['scrap_outsourcing_mr'] ?? 'no';
            $scrap_outsourcing_bh = $userSettings['scrap_outsourcing_bh'] ?? 'no';   

        // Start with the VoucherMeta query to get all outward transactions
        $voucherMetaQuery = VoucherMeta::with(['voucher.party', 'category', 'item'])
            ->whereHas('voucher', function ($q) use ($tenantId, $activeCompanyId) {
                $q->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->where('transaction_type', 's_inward');
            })
            ->where('remark', '!=', 'as-it-is') 
            ->join('voucher', 'voucher_meta.voucher_id', '=', 'voucher.id')
            ->orderBy('voucher.transaction_date', 'asc')
            ->select('voucher_meta.*');

        // Apply search and filters to voucher meta
        $this->applyFilters($voucherMetaQuery, $search, $partyId, $itemId, $categoryId, $voucherId, $transactionDateFrom, $transactionDateTo);

        // Execute voucher meta query
        $voucherMetas = $voucherMetaQuery->get();

        // Start with the ScrapTransaction query to get all scrap transactions
        $scrapTransactionsQuery = DB::table('scrap_transactions')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->whereIn('scrap_type',['inward', 'adjustment'])
            ->orderBy('date', 'asc');

        // Apply date filters to scrap transactions
        if ($transactionDateFrom && $transactionDateTo) {
            $scrapTransactionsQuery->whereBetween('date', [$transactionDateFrom, $transactionDateTo]);
        } elseif ($transactionDateFrom) {
            $scrapTransactionsQuery->where('date', '>=', $transactionDateFrom);
        } elseif ($transactionDateTo) {
            $scrapTransactionsQuery->where('date', '<=', $transactionDateTo);
        }

        // Apply party filter to scrap transactions
        if ($partyId) {
            $scrapTransactionsQuery->where('party_id', $partyId);
        }

        

        // Execute scrap transactions query
        $scrapTransactions = $scrapTransactionsQuery->get();

        // Initialize combined records and counters
        $combinedRecords = [];
        $summary = [
            'scrap_receivable' => 0,
            'scrap_taken' => 0,
            'scrap_balance' =>0,
            'scrap_transaction_count' =>0,
            'transaction_count' => 0,
            'transaction_mr' => 0,
            'transaction_cr' => 0,
            'transaction_bh' => 0,
            'transaction_ok' => 0,
            'transaction_asitis' => 0,
            'transaction_mr_qty'=> 0,
            'transaction_cr_qty'=> 0,
            'transaction_bh_qty'=> 0,
            'transaction_ok_qty'=> 0,
            'transaction_asitis_qty'=> 0,
        ];

        // Calculate opening balance as of transaction date from
        $openingBalance = $this->calculateOpeningBalancesubcontract($tenantId, $activeCompanyId, $partyId, $itemId, $transactionDateFrom);

        // Add opening balance entry at the beginning of combined records
            $combinedRecords[] = [
                'id' => 'opening-balance',
                'date' => $transactionDateFrom, // Start date of the report
                'voucher_type' => 'opening',
                'voucher_no' => '-',
                'party_id' => $partyId,
                'party_name' => $party ? $party->name : 'All Parties',
                'quantity' => null,
                'scrap_receivable' => $openingBalance['scrap_due'],
                'scrap_taken' => $openingBalance['scrap_given'],
                'balance' => $openingBalance['balance'],
                'vehicle_number' => null,
                'description' => 'Opening Balance as of ' . date('d-m-Y', strtotime($transactionDateFrom)),
                'item_id' => $itemId,
                'item_name' => $item ? $item->name : 'All Items',
                'item_code' => $item ? $item->item_code : null,
                'category_id' => null,
                'category_name' => null,
                'remark' => 'Opening Balance',
            ];

        $summary['scrap_receivable'] = $openingBalance['scrap_due'];  
        $summary['scrap_taken'] = $openingBalance['scrap_given'];

        // Process voucher transactions
        foreach ($voucherMetas as $voucherMeta) {
            $voucher = $voucherMeta->voucher;
            $scrapValue = $this->calculateScrapValueinward($voucherMeta, $userSettings);
            
            // Update counters based on remark
            $remark = strtolower($voucherMeta->remark ?? '');
             if ($remark === 'mr' && $scrap_outsourcing_mr === 'yes') {
                $summary['transaction_mr']++;
                $summary['transaction_mr_qty'] += $voucherMeta->item_quantity;
            } elseif ($remark === 'cr' && $scrap_outsourcing_cr === 'yes') {
                $summary['transaction_cr']++;
                $summary['transaction_cr_qty'] += $voucherMeta->item_quantity;
            } elseif ($remark === 'bh' && $scrap_outsourcing_bh === 'yes') {
                $summary['transaction_bh']++;
                $summary['transaction_bh_qty'] += $voucherMeta->item_quantity;
            } elseif ($remark === 'ok') {
                $summary['transaction_ok']++;
                $summary['transaction_ok_qty'] += $voucherMeta->item_quantity;
            } elseif ($remark === 'as-it-is') {
                $summary['transaction_asitis']++;
                $summary['transaction_asitis_qty'] += $voucherMeta->item_quantity;
            }

            $summary['scrap_receivable'] += $scrapValue;
            $summary['transaction_count']++;

            // Check if transaction should be shown based on remark and user settings
            $showTransaction = false;
            $remark = strtolower($voucherMeta->remark ?? ''); // Normalize remark to lowercase
            $quantity = $voucherMeta->item_quantity ?? 0;

            // Ensure user settings are available for each condition
            $scrap_outsourcing_cr = $userSettings['scrap_outsourcing_cr'] ?? 'no';
            $scrap_outsourcing_mr = $userSettings['scrap_outsourcing_mr'] ?? 'no';
            $scrap_outsourcing_bh = $userSettings['scrap_outsourcing_bh'] ?? 'no';

            // Determine if transaction should be shown based on remark and user settings
            switch ($remark) {
                case 'mr':
                    $showTransaction = ($scrap_outsourcing_mr === 'yes');
                    break;
                case 'cr':
                    $showTransaction = ($scrap_outsourcing_cr === 'yes');
                    break;
                case 'bh':
                    $showTransaction = ($scrap_outsourcing_bh === 'yes');
                    break;
                case 'ok':
                    $showTransaction = true; // Always show OK transactions
                    break;
                case 'as-it-is':
                    $showTransaction = ($quantity > 0); // Show only if quantity exists
                    break;
                default:
                    $showTransaction = true; // For any other remarks, show by default
            }

            if ($showTransaction) {

                $combinedRecords[] = [
                    'id' => $voucherMeta->id,
                    'date' => $voucher->transaction_date,
                    'voucher_type' => 'subcontract inward',
                    'voucher_no' => $voucher->voucher_no,
                    'party_id' => $voucher->party_id,
                    'party_name' => $voucher->party ? $voucher->party->name : null,
                    'quantity' => $voucherMeta->item_quantity,
                    'scrap_receivable' => $scrapValue,
                    'scrap_taken' => 0, // No scrap given for outward transactions
                    'balance' => $scrapValue, // Initial balance is equal to scrap_receivable
                    'vehicle_number' => $voucher->vehicle_number,
                    'description' => $voucher->description,
                    'item_id' => $voucherMeta->item_id,
                    'item_name' => $voucherMeta->item ? $voucherMeta->item->name : null,
                    'item_code' => $voucherMeta->item ? $voucherMeta->item->item_code : null,
                    'category_id' => $voucherMeta->category_id,
                    'category_name' => $voucherMeta->category ? $voucherMeta->category->name : null,
                    'remark' => $voucherMeta->remark,
                ];
            }
        }

        // Process scrap transactions
        foreach ($scrapTransactions as $scrapTransaction) {
            $summary['scrap_taken'] += $scrapTransaction->scrap_weight;
            $summary['transaction_count']++;
            $summary['scrap_transaction_count']++;
            $combinedRecords[] = [
                'id' => $scrapTransaction->id,
                'date' => $scrapTransaction->date,
                'voucher_type' => 'return scrap',
                'voucher_no' => $scrapTransaction->voucher_number,
                'party_id' => $scrapTransaction->party_id,
                'party_name' => $this->getPartyName($scrapTransaction->party_id), // You'll need to implement this method
                'quantity' => null, // Not applicable for scrap transactions
                'scrap_receivable' => 0, // No scrap due for return transactions
                'scrap_taken' => $scrapTransaction->scrap_weight,
                'balance' => -$scrapTransaction->scrap_weight, // Negative balance for returned scrap
                'vehicle_number' => $scrapTransaction->vehical_number,
                'description' => $scrapTransaction->description,
                'item_id' => null, // Not applicable for scrap transactions
                'item_name' => null,
                'item_code' => null,
                'category_id' => null,
                'category_name' => null,
                'remark' => "Scrap received",
            ];
        }

        // Sort combined records by date
        usort($combinedRecords, function($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });

        // Calculate running balance
       $runningBalance = $openingBalance['balance']; 
        foreach ($combinedRecords as &$record) {
             if ($record['id'] === 'opening-balance') {
                continue;
            }
            $runningBalance += $record['scrap_receivable'] - $record['scrap_taken'];
            $record['balance'] = $runningBalance;
            
        }

        $summary['scrap_balance'] = $record['balance'] ;
        $summary['transaction_count'] = $summary['transaction_mr'] +$summary['transaction_cr'] +$summary['transaction_bh']+$summary['transaction_ok']+$summary['transaction_asitis']+ $summary['scrap_transaction_count'];
        //  all records
        $data=[
            'records' => $combinedRecords,
            'summary' => $summary,
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
                'party' => $party ? [
                    'id' => $party->id,
                    'name' => $party->name,
                    'address_line_1' => $party->address1,
                    'address_line_2' => $party->address2,
                    'city' => $party->city,
                    'state' => $party->state->title,
                    'pincode' => $party->pincode,
                    'gst_number' => $party->gst_number,
                ] : null,
                'item' => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'code' => $item->item_code,
                    'hsn' => $item->hsn,
                    'description' => $item->description,
                ],
                'date_range' => [
                    'from' => $transactionDateFrom,
                    'to' => $transactionDateTo,
                ],
                'total_count' => count($combinedRecords),
                'userSettings'=>$userSettings
            ];

        // Configure Dompdf
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans'); // Important for special characters

        // Instantiate Dompdf
        $pdf = new Dompdf($options);

        // Load the HTML from the Blade view
        $view = view('pdf.scrapreceivableReport', ['data' => $data])->render();
        $pdf->loadHtml($view, 'UTF-8');

        // Set paper size and orientation
        $pdf->setPaper('A4', 'portrait');

        // Render the PDF
        $pdf->render();

        // Generate a dynamic filename
        $filename = "scrapreceivableReport_" . ($data['item']['code'] ?? 'item') . "_" . date('Ymd') . ".pdf";
        
        // Stream the PDF to the browser (inline view)
        return $pdf->stream($filename);  
    }
}

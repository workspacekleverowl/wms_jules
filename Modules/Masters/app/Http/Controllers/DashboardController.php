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

class DashboardController extends Controller
{
    public function getSalesReportChartData(Request $request)
    {
        // $response = $this->checkPermission('Sales-Job-Work-Reports-Download');
        
        // // If checkPermission returns a response (i.e., permission denied), return it.
        // if ($response) {
        //     return $response;
        // }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();
        
    
        // Get current financial year dates for weekly/monthly data
        $currentMonth = date('m');
        $currentYear = date('Y');
        
        if ($currentMonth < 4) {
            // Current FY is previous year April to current year March
            $fyStartYear = $currentYear - 1;
            $fyEndYear = $currentYear;
        } else {
            // Current FY is current year April to next year March
            $fyStartYear = $currentYear;
            $fyEndYear = $currentYear + 1;
        }
        
        $fyStartDate = $fyStartYear . '-04-01';
        $fyEndDate = $fyEndYear . '-03-31';
        
        // Get ALL transactions for current financial year (for weekly/monthly data)
        $currentFyQuery = VoucherMeta::with(['voucher.party', 'category', 'item'])
            ->whereHas('voucher', function ($q) use ($tenantId, $activeCompanyId, $fyStartDate, $fyEndDate) {
                $q->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->where('transaction_type', 'outward') // Sales are outward transactions
                ->whereBetween('transaction_date', [$fyStartDate, $fyEndDate]);
                
            })
            ->join('voucher', 'voucher_meta.voucher_id', '=', 'voucher.id')
            ->orderBy('voucher.transaction_date', 'asc')
            ->select('voucher_meta.*');
        
        $currentFyVoucherMetas = $currentFyQuery->get();
        
        // Get ALL transactions from database (for yearly data across all financial years)
        $allTransactionsQuery = VoucherMeta::with(['voucher.party', 'category', 'item'])
            ->whereHas('voucher', function ($q) use ($tenantId, $activeCompanyId) {
                $q->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->where('transaction_type', 'outward'); // Sales are outward transactions
                
            })
            ->join('voucher', 'voucher_meta.voucher_id', '=', 'voucher.id')
            ->orderBy('voucher.transaction_date', 'asc')
            ->select('voucher_meta.*');
        
        $allVoucherMetas = $allTransactionsQuery->get();
        
        // Initialize data structures
        $weeklyData = [];
        $monthlyData = [];
        $yearlyData = [];
        
        // Process current financial year transactions for weekly and monthly data
        foreach ($currentFyVoucherMetas as $voucherMeta) {
            $voucher = $voucherMeta->voucher;
            $transactionDate = $voucher->transaction_date;
            $quantity = $voucherMeta->item_quantity ?? 0;
            $rate = $voucherMeta->job_work_rate ?? 0;
            $gstPercentage = $voucherMeta->gst_percent_rate ?? 0;
            
            $totalPriceWithoutGst = $rate * $quantity;
            $gstAmount = ($totalPriceWithoutGst * $gstPercentage) / 100;
            $totalPriceWithGst = $totalPriceWithoutGst + $gstAmount;
            
            // Parse transaction date
            $dateObj = new \DateTime($transactionDate);
            $year = $dateObj->format('Y');
            $month = $dateObj->format('m');
            $day = $dateObj->format('d');
            
            // MONTHLY DATA (Financial Year Months: April to March)
            $monthKey = $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT);
            
            if (!isset($monthlyData[$monthKey])) {
                $monthNames = [
                    '01' => 'January', '02' => 'February', '03' => 'March',
                    '04' => 'April', '05' => 'May', '06' => 'June',
                    '07' => 'July', '08' => 'August', '09' => 'September',
                    '10' => 'October', '11' => 'November', '12' => 'December'
                ];
                
                $monthlyData[$monthKey] = [
                    'month' => (int)$month,
                    'year' => (int)$year,
                    'month_name' => $monthNames[$month],
                    'month_year' => $monthNames[$month] . ' ' . $year,
                    'total_without_gst' => 0,
                    'total_with_gst' => 0,
                    'transaction_count' => 0,
                    'total_quantity' => 0
                ];
            }
            
            $monthlyData[$monthKey]['total_without_gst'] += $totalPriceWithoutGst;
            $monthlyData[$monthKey]['total_with_gst'] += $totalPriceWithGst;
            $monthlyData[$monthKey]['transaction_count']++;
            $monthlyData[$monthKey]['total_quantity'] += $quantity;
            
            // WEEKLY DATA (Current Month Only) - Monday to Sunday weeks
            if ($year == date('Y') && $month == date('m')) {
                // Get the transaction date
                $transactionDateObj = new \DateTime($transactionDate);
                
                // Get first day of current month
                $firstDayOfMonth = new \DateTime(date('Y-m-01'));
                $lastDayOfMonth = new \DateTime(date('Y-m-t'));
                
                // Find the Monday of the week containing the first day of the month
                $firstMonday = clone $firstDayOfMonth;
                $dayOfWeek = $firstDayOfMonth->format('N'); // 1 = Monday, 7 = Sunday
                if ($dayOfWeek > 1) {
                    $firstMonday->modify('-' . ($dayOfWeek - 1) . ' days');
                }
                
                // Calculate which week this transaction falls into
                $daysDiff = $transactionDateObj->diff($firstMonday)->days;
                $weekNumber = floor($daysDiff / 7) + 1;
                
                // Calculate week start and end dates
                $weekStart = clone $firstMonday;
                $weekStart->modify('+' . (($weekNumber - 1) * 7) . ' days');
                $weekEnd = clone $weekStart;
                $weekEnd->modify('+6 days');
                
                // Adjust week start/end to be within the current month
                if ($weekStart < $firstDayOfMonth) {
                    $weekStart = clone $firstDayOfMonth;
                }
                if ($weekEnd > $lastDayOfMonth) {
                    $weekEnd = clone $lastDayOfMonth;
                }
                
                $weekKey = 'week_' . $weekNumber;
                
                if (!isset($weeklyData[$weekKey])) {
                    $weeklyData[$weekKey] = [
                        'week' => $weekNumber,
                        'week_label' => 'Week ' . $weekNumber,
                        'date_range' => [
                            'start' => $weekStart->format('Y-m-d'),
                            'end' => $weekEnd->format('Y-m-d'),
                            'start_day' => $weekStart->format('D'), // Mon, Tue, etc.
                            'end_day' => $weekEnd->format('D')
                        ],
                        'total_without_gst' => 0,
                        'total_with_gst' => 0,
                        'transaction_count' => 0,
                        'total_quantity' => 0
                    ];
                }
                
                $weeklyData[$weekKey]['total_without_gst'] += $totalPriceWithoutGst;
                $weeklyData[$weekKey]['total_with_gst'] += $totalPriceWithGst;
                $weeklyData[$weekKey]['transaction_count']++;
                $weeklyData[$weekKey]['total_quantity'] += $quantity;
            }
        }
        
        // Process ALL transactions for yearly data (across all financial years)
        foreach ($allVoucherMetas as $voucherMeta) {
            $voucher = $voucherMeta->voucher;
            $transactionDate = $voucher->transaction_date;
            $quantity = $voucherMeta->item_quantity ?? 0;
            $rate = $voucherMeta->job_work_rate ?? 0;
            $gstPercentage = $voucherMeta->gst_percent_rate ?? 0;
            
            $totalPriceWithoutGst = $rate * $quantity;
            $gstAmount = ($totalPriceWithoutGst * $gstPercentage) / 100;
            $totalPriceWithGst = $totalPriceWithoutGst + $gstAmount;
            
            // Parse transaction date
            $dateObj = new \DateTime($transactionDate);
            $transactionYear = (int)$dateObj->format('Y');
            $transactionMonth = (int)$dateObj->format('m');
            
            // Determine financial year for this transaction
            if ($transactionMonth < 4) {
                // Transaction is in Jan-Mar, so it belongs to FY that started in previous calendar year
                $financialYear = $transactionYear - 1;
            } else {
                // Transaction is in Apr-Dec, so it belongs to FY that started in same calendar year
                $financialYear = $transactionYear;
            }
            
            // Create financial year label (e.g., "2023-24")
            $fyLabel = $financialYear . '-' . substr(($financialYear + 1), 2);
            
            // YEARLY DATA (All Financial Years)
            if (!isset($yearlyData[$financialYear])) {
                $yearlyData[$financialYear] = [
                    'financial_year' => $financialYear,
                    'financial_year_label' => $fyLabel,
                    'start_date' => $financialYear . '-04-01',
                    'end_date' => ($financialYear + 1) . '-03-31',
                    'total_without_gst' => 0,
                    'total_with_gst' => 0,
                    'transaction_count' => 0,
                    'total_quantity' => 0
                ];
            }
            
            $yearlyData[$financialYear]['total_without_gst'] += $totalPriceWithoutGst;
            $yearlyData[$financialYear]['total_with_gst'] += $totalPriceWithGst;
            $yearlyData[$financialYear]['transaction_count']++;
            $yearlyData[$financialYear]['total_quantity'] += $quantity;
        }
        
        // Sort and format data
        ksort($weeklyData);
        ksort($monthlyData);
        ksort($yearlyData);
        
        // Convert to indexed arrays and round values
        $weeklyChartData = array_values(array_map(function($item) {
            return [
                'week' => $item['week'],
                'week_label' => $item['week_label'],
                'date_range' => $item['date_range'],
                'total_without_gst' => round($item['total_without_gst'], 2),
                'total_with_gst' => round($item['total_with_gst'], 2),
                'transaction_count' => $item['transaction_count'],
                'total_quantity' => $item['total_quantity']
            ];
        }, $weeklyData));
        
        $monthlyChartData = array_values(array_map(function($item) {
            return [
                'month' => $item['month'],
                'year' => $item['year'],
                'month_name' => $item['month_name'],
                'month_year' => $item['month_year'],
                'total_without_gst' => round($item['total_without_gst'], 2),
                'total_with_gst' => round($item['total_with_gst'], 2),
                'transaction_count' => $item['transaction_count'],
                'total_quantity' => $item['total_quantity']
            ];
        }, $monthlyData));
        
        $yearlyChartData = array_values(array_map(function($item) {
            return [
                'financial_year' => $item['financial_year'],
                'financial_year_label' => $item['financial_year_label'],
                'start_date' => $item['start_date'],
                'end_date' => $item['end_date'],
                'total_without_gst' => round($item['total_without_gst'], 2),
                'total_with_gst' => round($item['total_with_gst'], 2),
                'transaction_count' => $item['transaction_count'],
                'total_quantity' => $item['total_quantity']
            ];
        }, $yearlyData));
        
        // Calculate totals (now from all financial years)
        $grandTotalWithoutGst = array_sum(array_column($yearlyChartData, 'total_without_gst'));
        $grandTotalWithGst = array_sum(array_column($yearlyChartData, 'total_with_gst'));
        $totalTransactions = array_sum(array_column($yearlyChartData, 'transaction_count'));
        $totalQuantity = array_sum(array_column($yearlyChartData, 'total_quantity'));
        
        return response()->json([
            'status' => 200,
            'message' => 'Sales chart data retrieved successfully',
            'data' => [
                'current_financial_year' => [
                    'start_date' => $fyStartDate,
                    'end_date' => $fyEndDate,
                    'label' => 'FY ' . $fyStartYear . '-' . substr($fyEndYear, 2)
                ],
                'summary' => [
                    'grand_total_without_gst' => round($grandTotalWithoutGst, 2),
                    'grand_total_with_gst' => round($grandTotalWithGst, 2),
                    'total_transactions' => $totalTransactions,
                    'total_quantity' => $totalQuantity,
                    'total_financial_years' => count($yearlyChartData)
                ],
                'weekly' => $weeklyChartData,
                'monthly' => $monthlyChartData,
                'yearly' => $yearlyChartData
            ]
        ]);
    }

    public function getDashboardStockOverview(Request $request)
    {
    

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();
        
        // Get request parameters
        $partyId = $request->input('party_id');
        $search = $request->input('search');
        
        try {
            // Get all parent items with category only (party_id is JSON array, handle separately)
            $parentItemsQuery = Item::with(['category'])
                ->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->whereNull('parent_id'); // Only parent items
            
            // Apply party filter if provided
            if ($partyId) {
                $parentItemsQuery->whereJsonContains('party_id', (int) $partyId);
            }
            
            // Apply search filter if provided
           // Apply search filter for item name or party name
            if ($search) {
                // Get party IDs matching the search term
                $matchingPartyIds = Party::where('name', 'LIKE', '%' . $search . '%')->pluck('id')->toArray();

                $parentItemsQuery->where(function ($query) use ($search, $matchingPartyIds) {
                    $query->where('name', 'LIKE', '%' . $search . '%');

                    if (!empty($matchingPartyIds)) {
                        foreach ($matchingPartyIds as $id) {
                            $query->orWhereJsonContains('party_id', (int) $id);
                        }
                    }
                });
            }

            $parentItems = $parentItemsQuery->get();
            
            $stockData = [];
            
            foreach ($parentItems as $item) {
                // Get all child items for this parent
                $childItems = Item::where('parent_id', $item->id)
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $activeCompanyId)
                    ->pluck('id')
                    ->toArray();
                
                // Create array of all item IDs (parent + children)
                $allItemIds = array_merge([$item->id], $childItems);
                
                // Calculate running stock for all related items
                $runningStock = DB::table('voucher as v')
                    ->join('voucher_meta as vm', 'v.id', '=', 'vm.voucher_id')
                    ->where('v.tenant_id', $tenantId)
                    ->where('v.company_id', $activeCompanyId)
                    ->whereIn('vm.item_id', $allItemIds)
                    ->selectRaw('
                        COALESCE(SUM(
                            CASE 
                                WHEN v.transaction_type IN ("inward", "s_inward", "adjustment", "s_adjustment") 
                                    THEN vm.item_quantity 
                                ELSE -vm.item_quantity 
                            END
                        ), 0) as running_stock
                    ')
                    ->first();
                
                // Get party name and party_id from party_id JSON array
                $partyName = null;
                $partyIdValue = null;
                if ($item->party_id && is_array($item->party_id) && !empty($item->party_id)) {
                    // Get the first party ID from the array
                    $firstPartyId = $item->party_id[0];
                    $partyIdValue = $firstPartyId;
                    $party = DB::table('party')
                        ->where('id', $firstPartyId)
                        ->where('tenant_id', $tenantId)
                        ->where('company_id', $activeCompanyId)
                        ->first();
                    $partyName = $party ? $party->name : null;
                }
                
                $stockData[] = [
                    'item_id' => $item->id,
                    'item_name' => $item->name,
                    'party_id' => $partyIdValue,
                    'party_name' => $partyName,
                    'category_name' => $item->category ? $item->category->name : null,
                    'running_stock' => (float) ($runningStock->running_stock ?? 0)
                ];
            }
            
            return response()->json([
                'status' => 200,
                'message' => 'Dashboard stock overview retrieved successfully',
                'data' => $stockData
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Error retrieving stock overview: ' . $e->getMessage(),
            ], 200);
        }
    }

    public function getMonthlyStockReport(Request $request)
    {
    
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();
        
        // Get required parameter
        $itemId = $request->input('item_id');
        
        // Validate required parameter
        if (!$itemId) {
            return response()->json([
                'status' => 422,
                'message' => 'Item ID is required',
            ], 422);
        }
        
        try {
            // Calculate current financial year dates
            $currentMonth = date('m');
            $currentYear = date('Y');
            
            if ($currentMonth < 4) {
                // Current FY is previous year April to current year March
                $transactionDateFrom = ($currentYear - 1) . '-04-01';
                $transactionDateTo = $currentYear . '-03-31';
                $financialYear = ($currentYear - 1) . '-' . $currentYear;
            } else {
                // Current FY is current year April to next year March
                $transactionDateFrom = $currentYear . '-04-01';
                $transactionDateTo = ($currentYear + 1) . '-03-31';
                $financialYear = $currentYear . '-' . ($currentYear + 1);
            }
            
            // Get item details with relationships
            $parentItem = Item::with(['category'])
                ->where('id', $itemId)
                ->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->first();
            
            if (!$parentItem) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Item not found',
                ], 200);
            }
            
            // Get all child items
            $childItems = Item::where('parent_id', $itemId)
                ->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->pluck('id')
                ->toArray();
            
            // Create array of all item IDs (parent + children)
            $allItemIds = array_merge([$itemId], $childItems);
            
            // Get party name from parent item
            $partyName = null;
            $partyId = null;
            if ($parentItem->party_id && is_array($parentItem->party_id) && !empty($parentItem->party_id)) {
                $firstPartyId = $parentItem->party_id[0];
                $partyId = $firstPartyId;
                $party = DB::table('party')
                    ->where('id', $firstPartyId)
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $activeCompanyId)
                    ->first();
                $partyName = $party ? $party->name : null;
            }
            
            // Calculate opening balance (transactions before financial year start)
            $openingBalance = DB::table('voucher as v')
                ->join('voucher_meta as vm', 'v.id', '=', 'vm.voucher_id')
                ->where('v.tenant_id', $tenantId)
                ->where('v.company_id', $activeCompanyId)
                ->whereIn('vm.item_id', $allItemIds)
                ->where('v.transaction_date', '<', $transactionDateFrom)
                ->selectRaw('COALESCE(SUM(
                    CASE 
                        WHEN v.transaction_type IN ("inward", "s_inward", "adjustment", "s_adjustment") 
                            THEN vm.item_quantity 
                        ELSE -vm.item_quantity 
                    END
                ), 0) as opening_balance')
                ->first();
            
            // Get monthly stock data for the financial year
            $monthlyData = DB::table('voucher as v')
                ->join('voucher_meta as vm', 'v.id', '=', 'vm.voucher_id')
                ->where('v.tenant_id', $tenantId)
                ->where('v.company_id', $activeCompanyId)
                ->whereIn('vm.item_id', $allItemIds)
                ->whereBetween('v.transaction_date', [$transactionDateFrom, $transactionDateTo])
                ->selectRaw('
                    YEAR(v.transaction_date) as year,
                    MONTH(v.transaction_date) as month,
                    SUM(CASE 
                        WHEN v.transaction_type IN ("inward", "s_inward") 
                            THEN vm.item_quantity 
                        ELSE 0 
                    END) as inward_quantity,
                    SUM(CASE 
                        WHEN v.transaction_type IN ("outward", "s_outward") 
                            THEN vm.item_quantity 
                        ELSE 0 
                    END) as outward_quantity,
                    SUM(CASE 
                        WHEN v.transaction_type IN ("adjustment", "s_adjustment") 
                            THEN vm.item_quantity 
                        ELSE 0 
                    END) as adjustment_quantity
                ')
                ->groupBy('year', 'month')
                ->orderBy('year')
                ->orderBy('month')
                ->get();
            
            // Create monthly stock summary
            $monthlyStockData = [];
            $runningStock = (float) $openingBalance->opening_balance;
            
            // Generate all months in the financial year
            $startDate = new \DateTime($transactionDateFrom);
            $endDate = new \DateTime($transactionDateTo);
            
            while ($startDate <= $endDate) {
                $year = (int) $startDate->format('Y');
                $month = (int) $startDate->format('n');
                $monthName = $startDate->format('F Y');
                
                // Find data for this month
                $monthData = $monthlyData->where('year', $year)->where('month', $month)->first();
                
                $inward = $monthData ? (float) $monthData->inward_quantity : 0;
                $outward = $monthData ? (float) $monthData->outward_quantity : 0;
                $adjustment = $monthData ? (float) $monthData->adjustment_quantity : 0;
                
                // Calculate month-end stock
                $monthEndStock = $runningStock + $inward - $outward + $adjustment;
                
                $monthlyStockData[] = [
                    'year' => $year,
                    'month' => $month,
                    'month_name' => $monthName,
                    'opening_stock' => $runningStock,
                    'inward_quantity' => $inward,
                    'outward_quantity' => $outward,
                    'adjustment_quantity' => $adjustment,
                    'closing_stock' => $monthEndStock
                ];
                
                // Update running stock for next month
                $runningStock = $monthEndStock;
                
                // Move to next month
                $startDate->modify('first day of next month');
            }
            
            // Calculate financial year summary
            $totalInward = $monthlyData->sum('inward_quantity');
            $totalOutward = $monthlyData->sum('outward_quantity');
            $totalAdjustment = $monthlyData->sum('adjustment_quantity');
            $finalClosingStock = $runningStock;

            $currentMonthNum = (int) date('n');
            $currentYearNum = (int) date('Y');

            $monthlyStockData = array_map(function ($entry) use ($currentMonthNum, $currentYearNum) {
                
                if ($entry['year'] < $currentYearNum || ($entry['year'] == $currentYearNum && $entry['month'] <= $currentMonthNum)) {
                    $entry['month_stock'] = $entry['closing_stock'];
                } else {
                    $entry['month_stock'] = 0;
                }

                return $entry;
            }, $monthlyStockData);
            
            return response()->json([
                'status' => 200,
                'message' => 'Monthly stock report retrieved successfully',
                'data' => [
                    'item' => [
                        'item_id' => $parentItem->id,
                        'item_name' => $parentItem->name,
                        'party_id' => $partyId,
                        'party_name' => $partyName,
                        'category_name' => $parentItem->category ? $parentItem->category->name : null,
                    ],
                    'financial_year' => $financialYear,
                    'date_range' => [
                        'from' => $transactionDateFrom,
                        'to' => $transactionDateTo,
                    ],
                    'summary' => [
                        'opening_balance' => (float) $openingBalance->opening_balance,
                        'total_inward' => (float) $totalInward,
                        'total_outward' => (float) $totalOutward,
                        'total_adjustment' => (float) $totalAdjustment,
                        'closing_balance' => $finalClosingStock,
                    ],
                    'monthly_data' => $monthlyStockData,
                ],
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Error retrieving monthly stock report: ' . $e->getMessage(),
            ], 200);
        }
    }

    public function getAllTransactions(Request $request)
    {
        // $response = $this->checkPermission('All-Transactions-Menu');
        
        // // If checkPermission returns a response (i.e., permission denied), return it.
        // if ($response) {
        //     return $response;
        // }

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

        // Start with the Voucher query to get all voucher transactions
        $voucherQuery = Voucher::with(['party'])
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->orderBy('transaction_date', 'desc');

        // Apply filters to voucher
        if ($search) {
            $voucherQuery->where(function ($q) use ($search) {
                $q->where('voucher_no', 'like', "%{$search}%")
                ->orWhere('vehicle_number', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($partyId) {
            $voucherQuery->where('party_id', $partyId);
        }

        if ($voucherId) {
            $voucherQuery->where('id', $voucherId);
        }

        if ($transactionDateFrom && $transactionDateTo) {
            $voucherQuery->whereBetween('transaction_date', [$transactionDateFrom, $transactionDateTo]);
        } elseif ($transactionDateFrom) {
            $voucherQuery->where('transaction_date', '>=', $transactionDateFrom);
        } elseif ($transactionDateTo) {
            $voucherQuery->where('transaction_date', '<=', $transactionDateTo);
        }

        // Execute voucher query
        $vouchers = $voucherQuery->get();

        // Start with the ScrapTransaction query to get all scrap transactions
        $scrapTransactionsQuery = DB::table('scrap_transactions')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
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
            'total_voucher' => 0,
            'total_scrap' => 0,
            'total_transactions' => 0,
        ];

        // Process voucher transactions
        foreach ($vouchers as $voucher) {
            // Determine voucher type based on transaction_type
            $voucherType = '';
            if (in_array($voucher->transaction_type, ['inward', 'outward', 'adjustment'])) {
                $voucherType = 'inhouse voucher';
            } else {
                $voucherType = 'subcontract voucher';
            }

            $summary['total_voucher']++;

            $combinedRecords[] = [
                'id' => $voucher->id,
                'date' => $voucher->transaction_date,
                'voucher_type' => $voucherType,
                'voucher_no' => $voucher->voucher_no,
                'party_id' => $voucher->party_id,
                'party_name' => $voucher->party ? $voucher->party->name : null,
                'quantity' => $voucher->total_quantity ?? null,
                'vehicle_number' => $voucher->vehicle_number,
                'description' => $voucher->description,
                'transaction_type' => $voucher->transaction_type,
            ];
        }

        // Process scrap transactions
        foreach ($scrapTransactions as $scrapTransaction) {
            $summary['total_scrap']++;

            $combinedRecords[] = [
                'id' => $scrapTransaction->id,
                'date' => $scrapTransaction->date,
                'voucher_type' =>"scrap " .$scrapTransaction->scrap_type, // Use scrap_type as voucher_type
                'voucher_no' => $scrapTransaction->voucher_number,
                'party_id' => $scrapTransaction->party_id,
                'party_name' => $this->getPartyName($scrapTransaction->party_id), // You'll need to implement this method
                'quantity' => $scrapTransaction->scrap_weight,
                'vehicle_number' => $scrapTransaction->vehical_number,
                'description' => $scrapTransaction->description,
                'transaction_type' => $scrapTransaction->scrap_type,
            ];
        }

        // Calculate total transactions
        $summary['total_transactions'] = $summary['total_voucher'] + $summary['total_scrap'];

        // Sort combined records by date (newest first)
        usort($combinedRecords, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        // Paginate the combined records manually
        $total = count($combinedRecords);
        $lastPage = ceil($total / $perPage);
        $offset = ($page - 1) * $perPage;
        $paginatedRecords = array_slice($combinedRecords, $offset, $perPage);

        // Return response with pagination metadata
        return response()->json([
            'status' => 200,
            'message' => 'All transactions retrieved successfully',
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
     * Get party name by ID
     */
    private function getPartyName($partyId)
    {
        $party = DB::table('party')->where('id', $partyId)->first();
        return $party ? $party->name : null;
    }
 
 
}

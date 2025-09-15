<?php

namespace Modules\Masters\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Product;
use App\Models\party;
use Dompdf\Dompdf;
use Dompdf\Options;

class LedgerController extends Controller
{

    //common function to get stock from product stock table 
    private function getCombinedStockJson($productId)
    {
        $productStocks = DB::table('product_stock')
            ->where('product_id', $productId)
            ->get();
        
        $combinedStockJson = [];
        foreach ($productStocks as $stock) {
            $stockJson = json_decode($stock->stock_quantity, true);
            foreach ($stockJson as $fyId => $quantity) {
                if (!isset($combinedStockJson[$fyId])) {
                    $combinedStockJson[$fyId] = 0;
                }
                $combinedStockJson[$fyId] += $quantity;
            }
        }
        return $combinedStockJson;
    }

    //common function to get financial year
    private function getCurrentFinancialYear($date, $financialYears)
    {
        if ($financialYears->isEmpty()) {
            return null;
        }

        $matchingFY = $financialYears->first(function($fy) use ($date) {
            return $date->between($fy->start_date, $fy->end_date);
        });

        // If no matching FY found, return null
        if (!$matchingFY) {
            return null;
        }

        return $matchingFY;
    }

    //function to get product ledger
    public function getProductLedger(Request $request)
    {
        // Validate request parameters
        $request->validate([
            'product_id' => 'required|integer',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        $productId = $request->product_id;
        $startDate = $request->start_date 
            ? Carbon::parse($request->start_date)
            : Carbon::now()->startOfMonth();
    
        $endDate = $request->end_date 
            ? Carbon::parse($request->end_date)
            : Carbon::now()->endOfMonth();

        // Get financial years data
        $financialYears = DB::table('financial_year')
            ->orderBy('priority')
            ->get()
            ->map(function($fy) {
                $yearParts = explode('-', $fy->year);
                $fy->start_date = Carbon::createFromDate($yearParts[0], 4, 1);
                $fy->end_date = Carbon::createFromDate($yearParts[1], 3, 31);
                return $fy;
            });

        // Get combined stock JSON
        $combinedStockJson = $this->getCombinedStockJson($productId);

        // Get current financial year's opening balance
        $currentFY = $this->getCurrentFinancialYear($startDate, $financialYears);
        $fyOpeningBalance = 0; // Default to 0

        if ($currentFY) {
             // Find previous financial year based on priority
             $previousFY = $financialYears->first(function($fy) use ($currentFY) {
                return $fy->priority === ($currentFY->priority - 1);
            });

            // If previous FY exists, use its closing balance from JSON
            if ($previousFY && isset($combinedStockJson[$previousFY->id])) {
                $fyOpeningBalance = $combinedStockJson[$previousFY->id];
            }
        }

        // Calculate opening balance including adjustments
        $openingBalance = $this->calculateMonthOpeningBalance(
            $productId,
            $startDate,
            $fyOpeningBalance
        );

        // Get all transactions for the period
        $transactions = $this->getTransactions($productId, $startDate, $endDate);

        // Format transactions
        $formattedTransactions = [];
        $runningBalance = $openingBalance;
        $totalInward = 0;
        $totalOutward = 0;

        $Product = Product::with('company','category')->where('id', $productId)->first();

        // Add opening balance entry
        $formattedTransactions[] = [
            'transaction_date' => $startDate->format('Y-m-d'),
            'transaction_time' => '00:00:00',
            'product_id' => $productId,
            'product_name' => $transactions->first()->product_name ?? '',
            'party_name' => "-",
            'transaction_type' => 'opening_balance',
            'voucher_no' => '-',
            'job_work_rate' => 0,
            'material_price' => 0,
            'gst_percent_rate' => 0,
            'remark'=> '-',
            'inward' => 0,
            'outward' => 0,
            'balance' => $openingBalance
        ];

        foreach ($transactions as $transaction) {
            if (!in_array($transaction->transaction_type, ['adjustment', 's_adjustment'])) {
                $inward = 0;
                $outward = 0;

                if (in_array($transaction->transaction_type, ['inward', 's_inward'])) {
                    $inward = $transaction->product_quantity;
                    $runningBalance += $inward;
                    $totalInward += $inward;
                } else {
                    $outward = $transaction->product_quantity;
                    $runningBalance -= $outward;
                    $totalOutward += $outward;
                }

                $formattedTransactions[] = [
                    'transaction_date' => $transaction->transaction_date,
                    'transaction_time' => $transaction->transaction_time,
                    'product_id' => $transaction->product_id,
                    'product_name' => $transaction->product_name,
                    'party_name' => $transaction->party_name,
                    'transaction_type' => $transaction->transaction_type,
                    'voucher_no' => $transaction->voucher_no ?? null,
                    'job_work_rate' =>$transaction->job_work_rate ?? null,
                    'material_price' =>$transaction->material_price ?? null,
                    'gst_percent_rate' =>$transaction->gst_percent_rate ?? null,
                    'remark'=> $transaction->remark ?? null,
                    'inward' => $inward,
                    'outward' => $outward,
                    'balance' => $runningBalance
                ];
            }
        }

        $summary = [
            'selected_product' => $Product,
            'total_records' => count($formattedTransactions) - 1, // Excluding opening balance entry
            'opening_balance' => $openingBalance,
            'closing_balance' => $runningBalance,
            'total_inward' => $totalInward,
            'total_outward' => $totalOutward,
            'date_range' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d')
            ]
        ];

        return response()->json([
            'status' => 'success',
            'summary' => $summary,
            'data' => $formattedTransactions
        ]);
    }

    //Helper function to get product ledger
    private function calculateMonthOpeningBalance($productId, $date, $fyOpeningBalance)
    {
        $balance = $fyOpeningBalance;
        $startOfMonth = $date->copy()->startOfMonth();
        
        // Get all transactions up to the previous month
        $previousTransactions = DB::table('voucher')
            ->join('voucher_meta', 'voucher.id', '=', 'voucher_meta.voucher_id')
            ->where('voucher_meta.product_id', $productId)
            ->where('voucher.transaction_date', '<', $startOfMonth)
            ->orderBy('voucher.transaction_date')
            ->orderBy('voucher.transaction_time')
            ->get();

        // Calculate balance from previous months' transactions
        foreach ($previousTransactions as $trans) {
            if (in_array($trans->transaction_type, ['inward', 's_inward'])) {
                $balance += $trans->product_quantity;
            } elseif (in_array($trans->transaction_type, ['outward', 's_outward'])) {
                $balance -= $trans->product_quantity;
            }
        }

        // Add current month's adjustments to opening balance
        $previousMonthAdjustments = DB::table('voucher')
            ->join('voucher_meta', 'voucher.id', '=', 'voucher_meta.voucher_id')
            ->where('voucher_meta.product_id', $productId)
            ->whereIn('voucher.transaction_type', ['adjustment', 's_adjustment'])
            ->where('voucher.transaction_date', '<', $startOfMonth)
            ->orderBy('voucher.transaction_date')
            ->orderBy('voucher.transaction_time')
            ->get();

        foreach ($previousMonthAdjustments as $adjustment) {
            $balance += $adjustment->product_quantity; // Assuming positive/negative quantity for adjustments
        }

        // Add current month's adjustments to opening balance
        $currentMonthAdjustments = DB::table('voucher')
            ->join('voucher_meta', 'voucher.id', '=', 'voucher_meta.voucher_id')
            ->where('voucher_meta.product_id', $productId)
            ->whereIn('voucher.transaction_type', ['adjustment', 's_adjustment'])
            ->whereYear('voucher.transaction_date', $date->year)
            ->whereMonth('voucher.transaction_date', $date->month)
            ->get();

        foreach ($currentMonthAdjustments as $adjustment) {
            $balance += $adjustment->product_quantity; // Assuming positive/negative quantity for adjustments
        }

        return $balance;
    }

    //Helper function to get product ledger
    private function getTransactions($productId, $startDate, $endDate)
    {
        $query = DB::table('voucher')
            ->join('party', 'voucher.party_id', '=', 'party.id')
            ->join('voucher_meta', 'voucher.id', '=', 'voucher_meta.voucher_id')
            ->join('products', 'voucher_meta.product_id', '=', 'products.id')
            ->select(
                'voucher.transaction_date',
                'voucher.transaction_time',
                'voucher.transaction_type',
                'voucher.voucher_no',
                'voucher_meta.product_id',
                'voucher_meta.job_work_rate',
                'voucher_meta.material_price',
                'voucher_meta.gst_percent_rate',
                'voucher_meta.remark',
                'products.name as product_name',
                'party.name as party_name',
                'voucher_meta.product_quantity'
            )
            ->where('voucher_meta.product_id', $productId)
            ->whereBetween('voucher.transaction_date', [$startDate, $endDate]);
           

            return $query->orderBy('voucher.transaction_date')
                ->orderBy('voucher.transaction_time')
                ->get();
    }


    //function to get supplier product ledger
    public function getsupplierLedger(Request $request)
    {
        // Validate request parameters
        $request->validate([
            'product_id' => 'required|integer',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'party_id' => 'required|integer',
        ]);

        $productId = $request->product_id;
        $startDate = $request->start_date 
            ? Carbon::parse($request->start_date)
            : Carbon::now()->startOfMonth();

        $endDate = $request->end_date 
            ? Carbon::parse($request->end_date)
            : Carbon::now()->endOfMonth();
        $partyId = $request->party_id;

        // Get financial years data
        $financialYears = DB::table('financial_year')
            ->orderBy('priority')
            ->get()
            ->map(function($fy) {
                $yearParts = explode('-', $fy->year);
                $fy->start_date = Carbon::createFromDate($yearParts[0], 4, 1);
                $fy->end_date = Carbon::createFromDate($yearParts[1], 3, 31);
                return $fy;
            });

        // Get combined stock JSON
        $combinedStockJson = $this->getCombinedStockJson($productId);

        // Get current financial year's opening balance
        $currentFY = $this->getCurrentFinancialYear($startDate, $financialYears);
        $fyOpeningBalance = 0; // Default to 0

        if ($currentFY) {
             // Find previous financial year based on priority
             $previousFY = $financialYears->first(function($fy) use ($currentFY) {
                return $fy->priority === ($currentFY->priority - 1);
            });

            // If previous FY exists, use its closing balance from JSON
            if ($previousFY && isset($combinedStockJson[$previousFY->id])) {
                $fyOpeningBalance = $combinedStockJson[$previousFY->id];
            }
        }

        // Calculate opening balance including adjustments
        $openingBalance = $this->calculatesupplierMonthOpeningBalance(
            $productId,
            $startDate,
            $fyOpeningBalance
        );

        // Get all transactions for the period
        $transactions = $this->getsupplierTransactions($productId, $startDate, $endDate, $partyId);

        // Format transactions
        $formattedTransactions = [];
        $runningBalance = $openingBalance;
        $totalInward = 0;
        $totalOutward = 0;

        $Product = Product::with('company','category')->where('id', $productId)->first();
        $Party = party::where('id',$partyId)->first();
        // Add opening balance entry
        $formattedTransactions[] = [
            'transaction_date' => $startDate->format('Y-m-d'),
            'transaction_time' => '00:00:00',
            'product_id' => $productId,
            'product_name' => $transactions->first()->product_name ?? '',
            'transaction_type' => 'opening_balance',
            'voucher_no' => '-',
            'job_work_rate' => 0,
            'material_price' => 0,
            'gst_percent_rate' => 0,
            'remark'=> '-',
            'inward' => 0,
            'outward' => 0,
            'balance' => $openingBalance
        ];

        foreach ($transactions as $transaction) {
            if (!in_array($transaction->transaction_type, ['adjustment', 's_adjustment','s_inward','s_outward'])) {
                $inward = 0;
                $outward = 0;

                if (in_array($transaction->transaction_type, ['inward'])) {
                    $inward = $transaction->product_quantity;
                    $runningBalance += $inward;
                    $totalInward += $inward;
                } else {
                    $outward = $transaction->product_quantity;
                    $runningBalance -= $outward;
                    $totalOutward += $outward;
                }

                $formattedTransactions[] = [
                    'transaction_date' => $transaction->transaction_date,
                    'transaction_time' => $transaction->transaction_time,
                    'product_id' => $transaction->product_id,
                    'product_name' => $transaction->product_name,
                    'transaction_type' => $transaction->transaction_type,
                    'voucher_no' => $transaction->voucher_no ?? null,
                    'job_work_rate' =>$transaction->job_work_rate ?? null,
                    'material_price' =>$transaction->material_price ?? null,
                    'gst_percent_rate' =>$transaction->gst_percent_rate ?? null,
                    'remark'=> $transaction->remark ?? null,
                    'inward' => $inward,
                    'outward' => $outward,
                    'balance' => $runningBalance
                ];
            }
        }

        $summary = [
            'selected_product' => $Product,
            'selected_party' => $Party,
            'total_records' => count($formattedTransactions) - 1, // Excluding opening balance entry
            'opening_balance' => $openingBalance,
            'closing_balance' => $runningBalance,
            'total_inward' => $totalInward,
            'total_outward' => $totalOutward,
            'date_range' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d')
            ]
        ];

        return response()->json([
            'status' => 'success',
            'summary' => $summary,
            'data' => $formattedTransactions
        ]);
    }

    //Helper function to get supplier product ledger
    private function getsupplierTransactions($productId, $startDate, $endDate, $partyId = null)
    {
        $query = DB::table('voucher')
            ->join('voucher_meta', 'voucher.id', '=', 'voucher_meta.voucher_id')
            ->join('products', 'voucher_meta.product_id', '=', 'products.id')
            ->select(
                'voucher.transaction_date',
                'voucher.transaction_time',
                'voucher.transaction_type',
                'voucher.voucher_no',
                'voucher_meta.product_id',
                'voucher_meta.job_work_rate',
                'voucher_meta.material_price',
                'voucher_meta.gst_percent_rate',
                'voucher_meta.remark',
                'products.name as product_name',
                'voucher_meta.product_quantity'
            )
            ->where('voucher_meta.product_id', $productId)
            ->whereBetween('voucher.transaction_date', [$startDate, $endDate]);
           
            // Apply party filter if specified
            if ($partyId) {
                $query->where('voucher.party_id', $partyId); // Assuming 'party_id' is a column in 'voucher' table
            }

            return $query->orderBy('voucher.transaction_date')
                ->orderBy('voucher.transaction_time')
                ->get();
    }

     //Helper function to get supplier product ledger
     private function calculatesupplierMonthOpeningBalance($productId, $date, $fyOpeningBalance)
     {
         $balance = $fyOpeningBalance;
         $startOfMonth = $date->copy()->startOfMonth();
         
         // Get all transactions up to the previous month
         $previousTransactions = DB::table('voucher')
             ->join('voucher_meta', 'voucher.id', '=', 'voucher_meta.voucher_id')
             ->where('voucher_meta.product_id', $productId)
             ->where('voucher.transaction_date', '<', $startOfMonth)
             ->orderBy('voucher.transaction_date')
             ->orderBy('voucher.transaction_time')
             ->get();
 
         // Calculate balance from previous months' transactions
         foreach ($previousTransactions as $trans) {
             if (in_array($trans->transaction_type, ['inward'])) {
                 $balance += $trans->product_quantity;
             } elseif (in_array($trans->transaction_type, ['outward'])) {
                 $balance -= $trans->product_quantity;
             }
         }
 
         // Add current month's adjustments to opening balance
         $previousMonthAdjustments = DB::table('voucher')
             ->join('voucher_meta', 'voucher.id', '=', 'voucher_meta.voucher_id')
             ->where('voucher_meta.product_id', $productId)
             ->whereIn('voucher.transaction_type', ['adjustment'])
             ->where('voucher.transaction_date', '<', $startOfMonth)
             ->orderBy('voucher.transaction_date')
             ->orderBy('voucher.transaction_time')
             ->get();
 
         foreach ($previousMonthAdjustments as $adjustment) {
             $balance += $adjustment->product_quantity; // Assuming positive/negative quantity for adjustments
         }
 
         // Add current month's adjustments to opening balance
         $currentMonthAdjustments = DB::table('voucher')
             ->join('voucher_meta', 'voucher.id', '=', 'voucher_meta.voucher_id')
             ->where('voucher_meta.product_id', $productId)
             ->whereIn('voucher.transaction_type', ['adjustment'])
             ->whereYear('voucher.transaction_date', $date->year)
             ->whereMonth('voucher.transaction_date', $date->month)
             ->get();
 
         foreach ($currentMonthAdjustments as $adjustment) {
             $balance += $adjustment->product_quantity; // Assuming positive/negative quantity for adjustments
         }
 
         return $balance;
     }

     //function to get supplier product ledger
    public function getsubcontractLedger(Request $request)
    {
        // Validate request parameters
        $request->validate([
            'product_id' => 'required|integer',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'party_id' => 'required|integer',
        ]);

        $productId = $request->product_id;
        $startDate = $request->start_date 
            ? Carbon::parse($request->start_date)
            : Carbon::now()->startOfMonth();

        $endDate = $request->end_date 
            ? Carbon::parse($request->end_date)
            : Carbon::now()->endOfMonth();
            
        $partyId = $request->party_id;

        // Get financial years data
        $financialYears = DB::table('financial_year')
            ->orderBy('priority')
            ->get()
            ->map(function($fy) {
                $yearParts = explode('-', $fy->year);
                $fy->start_date = Carbon::createFromDate($yearParts[0], 4, 1);
                $fy->end_date = Carbon::createFromDate($yearParts[1], 3, 31);
                return $fy;
            });

        // Get combined stock JSON
        $combinedStockJson = $this->getCombinedStockJson($productId);

        // Get current financial year's opening balance
        $currentFY = $this->getCurrentFinancialYear($startDate, $financialYears);
        $fyOpeningBalance = 0; // Default to 0

        if ($currentFY) {
             // Find previous financial year based on priority
             $previousFY = $financialYears->first(function($fy) use ($currentFY) {
                return $fy->priority === ($currentFY->priority - 1);
            });

            // If previous FY exists, use its closing balance from JSON
            if ($previousFY && isset($combinedStockJson[$previousFY->id])) {
                $fyOpeningBalance = $combinedStockJson[$previousFY->id];
            }
        }

        // Calculate opening balance including adjustments
        $openingBalance = $this->calculatesubcontractMonthOpeningBalance(
            $productId,
            $startDate,
            $fyOpeningBalance
        );

        // Get all transactions for the period
        $transactions = $this->getsubcontractTransactions($productId, $startDate, $endDate, $partyId);

        // Format transactions
        $formattedTransactions = [];
        $runningBalance = $openingBalance;
        $totalInward = 0;
        $totalOutward = 0;

        $Product = Product::with('company','category')->where('id', $productId)->first();
        $Party = party::where('id',$partyId)->first();
        // Add opening balance entry
        $formattedTransactions[] = [
            'transaction_date' => $startDate->format('Y-m-d'),
            'transaction_time' => '00:00:00',
            'product_id' => $productId,
            'product_name' => $transactions->first()->product_name ?? '',
            'transaction_type' => 'opening_balance',
            'voucher_no' => '-',
            'job_work_rate' => 0,
            'material_price' => 0,
            'gst_percent_rate' => 0,
            'remark'=> '-',
            'inward' => 0,
            'outward' => 0,
            'balance' => $openingBalance
        ];

        foreach ($transactions as $transaction) {
            if (!in_array($transaction->transaction_type, ['adjustment', 's_adjustment','inward','outward'])) {
                $inward = 0;
                $outward = 0;

                if (in_array($transaction->transaction_type, ['s_inward'])) {
                    $inward = $transaction->product_quantity;
                    $runningBalance += $inward;
                    $totalInward += $inward;
                } else {
                    $outward = $transaction->product_quantity;
                    $runningBalance -= $outward;
                    $totalOutward += $outward;
                }

                $formattedTransactions[] = [
                    'transaction_date' => $transaction->transaction_date,
                    'transaction_time' => $transaction->transaction_time,
                    'product_id' => $transaction->product_id,
                    'product_name' => $transaction->product_name,
                    'transaction_type' => $transaction->transaction_type,
                    'voucher_no' => $transaction->voucher_no ?? null,
                    'job_work_rate' =>$transaction->job_work_rate ?? null,
                    'material_price' =>$transaction->material_price ?? null,
                    'gst_percent_rate' =>$transaction->gst_percent_rate ?? null,
                    'remark'=> $transaction->remark ?? null,
                    'inward' => $inward,
                    'outward' => $outward,
                    'balance' => $runningBalance
                ];
            }
        }

        $summary = [
            'selected_product' => $Product,
            'selected_party' => $Party,
            'total_records' => count($formattedTransactions) - 1, // Excluding opening balance entry
            'opening_balance' => $openingBalance,
            'closing_balance' => $runningBalance,
            'total_inward' => $totalInward,
            'total_outward' => $totalOutward,
            'date_range' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d')
            ]
        ];

        return response()->json([
            'status' => 'success',
            'summary' => $summary,
            'data' => $formattedTransactions
        ]);
    }

    //Helper function to get supplier product ledger
    private function getsubcontractTransactions($productId, $startDate, $endDate, $partyId = null)
    {
        $query = DB::table('voucher')
            ->join('voucher_meta', 'voucher.id', '=', 'voucher_meta.voucher_id')
            ->join('products', 'voucher_meta.product_id', '=', 'products.id')
            ->select(
                'voucher.transaction_date',
                'voucher.transaction_time',
                'voucher.transaction_type',
                'voucher.voucher_no',
                'voucher_meta.product_id',
                'voucher_meta.job_work_rate',
                'voucher_meta.material_price',
                'voucher_meta.gst_percent_rate',
                'voucher_meta.remark',
                'products.name as product_name',
                'voucher_meta.product_quantity'
            )
            ->where('voucher_meta.product_id', $productId)
            ->whereBetween('voucher.transaction_date', [$startDate, $endDate]);
           
            // Apply party filter if specified
            if ($partyId) {
                $query->where('voucher.party_id', $partyId); // Assuming 'party_id' is a column in 'voucher' table
            }

            return $query->orderBy('voucher.transaction_date')
                ->orderBy('voucher.transaction_time')
                ->get();
    }

     //Helper function to get supplier product ledger
    private function calculatesubcontractMonthOpeningBalance($productId, $date, $fyOpeningBalance)
    {
         $balance = $fyOpeningBalance;
         $startOfMonth = $date->copy()->startOfMonth();
         
         // Get all transactions up to the previous month
         $previousTransactions = DB::table('voucher')
             ->join('voucher_meta', 'voucher.id', '=', 'voucher_meta.voucher_id')
             ->where('voucher_meta.product_id', $productId)
             ->where('voucher.transaction_date', '<', $startOfMonth)
             ->orderBy('voucher.transaction_date')
             ->orderBy('voucher.transaction_time')
             ->get();
 
         // Calculate balance from previous months' transactions
         foreach ($previousTransactions as $trans) {
             if (in_array($trans->transaction_type, ['s_inward'])) {
                 $balance += $trans->product_quantity;
             } elseif (in_array($trans->transaction_type, ['s_outward'])) {
                 $balance -= $trans->product_quantity;
             }
         }
 
         // Add current month's adjustments to opening balance
         $previousMonthAdjustments = DB::table('voucher')
             ->join('voucher_meta', 'voucher.id', '=', 'voucher_meta.voucher_id')
             ->where('voucher_meta.product_id', $productId)
             ->whereIn('voucher.transaction_type', ['s_adjustment'])
             ->where('voucher.transaction_date', '<', $startOfMonth)
             ->orderBy('voucher.transaction_date')
             ->orderBy('voucher.transaction_time')
             ->get();
 
         foreach ($previousMonthAdjustments as $adjustment) {
             $balance += $adjustment->product_quantity; // Assuming positive/negative quantity for adjustments
         }
 
         // Add current month's adjustments to opening balance
         $currentMonthAdjustments = DB::table('voucher')
             ->join('voucher_meta', 'voucher.id', '=', 'voucher_meta.voucher_id')
             ->where('voucher_meta.product_id', $productId)
             ->whereIn('voucher.transaction_type', ['s_adjustment'])
             ->whereYear('voucher.transaction_date', $date->year)
             ->whereMonth('voucher.transaction_date', $date->month)
             ->get();
 
         foreach ($currentMonthAdjustments as $adjustment) {
             $balance += $adjustment->product_quantity; // Assuming positive/negative quantity for adjustments
         }
 
         return $balance;
    }

    public function getMonthlyProductLedger(Request $request)
    {
        // Validate request parameters
        $request->validate([
            'product_id' => 'required|integer',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
        ]);

        $productId = $request->product_id;
        $startDate = Carbon::parse($request->start_date)->startOfMonth();
        $endDate = Carbon::parse($request->end_date)->endOfMonth();

        $monthlyLedgers = [];
        $currentDate = $startDate;

        while ($currentDate->lessThanOrEqualTo($endDate)) {
            // Prepare start and end dates for the current month
            $monthStart = $currentDate->copy()->startOfMonth();
            $monthEnd = $currentDate->copy()->endOfMonth();

            // Call the existing getProductLedger function
            $ledgerRequest = new Request([
                'product_id' => $productId,
                'start_date' => $monthStart->format('Y-m-d'),
                'end_date' => $monthEnd->format('Y-m-d'),
            ]);

            $ledgerResponse = $this->getProductLedger($ledgerRequest);
            $ledgerData = json_decode($ledgerResponse->getContent(), true);

            // Append monthly data to the result
            $monthlyLedgers[] = [
                'month' => $monthStart->format('F Y'),
                'summary' => $ledgerData['summary'],
                'data' => $ledgerData['data'],
            ];

            // Move to the next month
            $currentDate->addMonth();
        }

        // return response()->json([
        //     'status' => 'success',
        //     'monthly_ledgers' => $monthlyLedgers,
        // ]);

       // Configure PDF options
       $options = new Options();
       $options->set('isHtml5ParserEnabled', true);
       $options->set('isRemoteEnabled', true);

       // Generate HTML content
       $view = view('pdf.ledgerpdf', [
           'monthlyLedgers' => $monthlyLedgers,
           'startDate' => $request->start_date,
           'endDate' => $request->end_date
       ])->render();

      // Initialize Dompdf
      $pdf = new Dompdf($options);
    
      // Load HTML content
      $pdf->loadHtml($view, 'UTF-8');
  
      // Set paper size and orientation
      $pdf->setPaper('A4', 'portrait');
  
      // Render the PDF
      $pdf->render();
  
      // Add page numbers
      $canvas = $pdf->getCanvas();
      $footerText = '{PAGE_NUM}/{PAGE_COUNT}';
      $font = $pdf->getFontMetrics()->get_font("Arial", "normal");
      $fontSize = 10;
      $x = $canvas->get_width() - 60; // Adjust X-coordinate for right alignment
      $y = $canvas->get_height() - 30; // Adjust Y-coordinate for footer
  
      $canvas->page_text($x, $y, $footerText, $font, $fontSize, [0, 0, 0]);
  
      

        // Stream PDF
        return $pdf->stream('product_ledger.pdf');

    }
    


}

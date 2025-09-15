<?php

namespace Modules\Masters\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Jobs\GenerateVoucherTestData;
use Illuminate\Support\Facades\Auth;
use App\Exports\VoucherTransactionsExport;
use Maatwebsite\Excel\Facades\Excel;
use Carbon\Carbon;

class TestController extends Controller
{
    public function generate(Request $request)
    {
        try {

            $user = $request->user(); 

            $financialYears = [
                ['id' => 1, 'year' => '2023-2024', 'start_date' => '2023-04-01', 'end_date' => '2024-03-31'],
                ['id' => 2, 'year' => '2024-2025', 'start_date' => '2024-04-01', 'end_date' => '2025-03-31'],
                ['id' => 3, 'year' => '2025-2026', 'start_date' => '2025-04-01', 'end_date' => '2026-03-31'],
                ['id' => 4, 'year' => '2026-2027', 'start_date' => '2026-04-01', 'end_date' => '2027-03-31']
            ];

            foreach ($financialYears as $year) {
                GenerateVoucherTestData::dispatch(
                    $year,
                    100,  // batch size
                    500,  // records per year
                    $user,
                )->onQueue('test-data');
            }

            return response()->json([
                'status' => 200,
                'message' => 'Test data generation has been queued. 2000 records will be generated in batches.'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to queue test data generation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function exportVoucherTransactions(Request $request)
    {
        try {
            $user = $request->user();
            $tenantId = $user->tenant_id;
            $companyId = $user->getActiveCompanyId();

            // Create a unique filename with timestamp
        $fileName = 'voucher_transactions_' . Carbon::now()->format('Y-m-d_H-i-s') . '.xlsx';
        
        // Define the storage path in public directory
        $filePath = public_path('exports/' . $fileName);
        
        // Ensure the exports directory exists
        if (!file_exists(public_path('exports'))) {
            mkdir(public_path('exports'), 0777, true);
        }
        
        // Store the file
        Excel::store(
            new VoucherTransactionsExport($tenantId, $companyId),
            'exports/' . $fileName,
            'public'
        );
        
        // Return the downloadable URL
        return response()->json([
            'status' => 200,
            'message' => 'Export generated successfully',
            'file_url' => asset('exports/' . $fileName)
        ]);
        
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to generate export: ' . $e->getMessage()
            ], 500);
        }
}
}



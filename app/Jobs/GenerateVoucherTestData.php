<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class GenerateVoucherTestData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $year;
    protected $batchSize;
    protected $totalRecords;
    protected $user;

    public function __construct($year, $batchSize = 100, $totalRecords = 500, $user)
    {
        $this->year = $year;
        $this->batchSize = $batchSize;
        $this->totalRecords = $totalRecords;
        $this->user = $user;
    }

    public function handle()
    {
        Auth::login($this->user);
        $startDate = Carbon::parse($this->year['start_date']);
        $endDate = Carbon::parse($this->year['end_date']);
        $processedCount = 0;

        // Process in batches
        while ($processedCount < $this->totalRecords) {
            $batchSize = min($this->batchSize, $this->totalRecords - $processedCount);
            
            for ($i = 0; $i < $batchSize; $i++) {
                $this->generateSingleVoucher($startDate, $endDate, $processedCount + $i);
            }
            
            $processedCount += $batchSize;
            Log::info("Processed {$processedCount} records for year {$this->year['year']}");
        }
        Auth::logout();
    }

    private function generateSingleVoucher($startDate, $endDate, $counter)
    {
        try {
            // Determine transaction type with 10% chance for adjustments
            $transactionType = $this->getRandomTransactionType();
            
            // Set party ID based on transaction type
            $partyId = in_array($transactionType, ['s_inward', 's_outward', 's_adjustment']) ? 5 : 4;
            
            // Generate random date within financial year
            $transactionDate = $startDate->copy()->addDays(rand(0, $endDate->diffInDays($startDate)));
            
            $remarkOptions = ['ok', 'as-it-is', 'mr', 'cr', 'bh'];

            // Create request data
            $requestData = [
                'party_id' => $partyId,
                'transaction_type' => $transactionType,
                'transaction_date' => $transactionDate->format('Y-m-d'),
                'issue_date' => $transactionDate->format('Y-m-d H:i:s'),
                'transaction_time' => sprintf('%02d:%02d:00', rand(8, 17), rand(0, 59)),
                'voucher_no' => $this->generateVoucherNo($this->year['year'], $counter + 1),
                'vehicle_number' => 'TEST' . rand(1000, 9999),
                'description' => "Test transaction for {$this->year['year']}",
                'products' => [
                    [
                        'category_id' => 78,
                        'product_id' => 17,
                        'product_quantity' => rand(1, 10),
                        'remark' => in_array($transactionType, ['outward', 's_inward']) ? 
                             $remarkOptions[array_rand($remarkOptions)]  : null
                    ]
                ]
            ];

            // Create request object
            $request = request();
            $request->merge($requestData);
            
            // Set the authenticated user
            // $user = \App\Models\User::find($this->userId);
            $request->setUserResolver(function ()  {
                return $this->user;
            });

            // Call the store method
            app(\Modules\Masters\Http\Controllers\VoucherController::class)->store($request);

        } catch (\Exception $e) {
            Log::error("Error generating voucher for year {$this->year['year']}: " . $e->getMessage());
        }
    }

    private function getRandomTransactionType()
    {
        if (rand(1, 100) <= 10) {
            return rand(0, 1) ? 'adjustment' : 's_adjustment';
        }
        
        $types = ['inward', 'outward', 's_inward', 's_outward'];
        return $types[array_rand($types)];
    }

    private function generateVoucherNo($year, $counter)
    {
        $prefix = substr(str_replace('-', '', $year), 2, 4);
        return sprintf("TEST/%s/%04d", $prefix, $counter);
    }

    
}

<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Sales Report</title>
    <style>
        @page { 
            margin: 20px 25px; 
            font-family: 'DejaVu Sans', sans-serif; 
        }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10px;
            line-height: 1.4;
            color: #000;
        }
        .company-name { font-size: 16px; text-align: center; font-weight: bold; margin-bottom: 5px; }
        .center-text { text-align: center; font-size: 10px; margin-bottom: 2px; }
        .divider { border-bottom: 1px solid black; margin: 0 150px 5px 150px; }
        .report-title { font-size: 14px; text-align: center; font-weight: bold; margin: 5px 0; }
        .table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .table-header { background-color: #f2f2f2; border-top: 1px solid black; border-bottom: 1px solid black; }
        .table th, .table td { padding: 4px; vertical-align: middle; border-bottom: 0.5px solid #ccc; }
        .header-cell { font-weight: bold; font-size: 10px; text-align: center; }
        .cell { font-size: 10px; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .bold { font-weight: bold; }
        .footer-row { background-color: #f2f2f2; border-top: 1px solid black; border-bottom: 1px solid black; font-weight: bold; }
        .summary-box { margin-top: 15px; border: 1px solid #333; padding: 10px; page-break-inside: avoid; }
        .summary-title { font-size: 12px; font-weight: bold; margin-bottom: 8px; text-align: center; }
        .summary-table td { font-size: 10px; padding: 2px 5px; }
        .page-break { page-break-after: always; }
        .page-footer {
            position: fixed;
            bottom: 10px;
            right: 20px;
            font-size: 14px;
        }
        /* Added for striped rows */
        .striped-light { background-color: #ffffff; }
        .striped-dark { background-color: #f9f9f9; }
    </style>
</head>
<body>
    @php
        // --- Settings Flags ---
        $showRateColumns = ($data['userSettings']['include_jobwork_rate_in_report'] ?? 'yes') !== 'no';
        $showGstColumn = $showRateColumns && (($data['userSettings']['jobwork_show_gst'] ?? 'yes') !== 'no');

        // --- New Pagination Logic ---
        $allTransactions = $data['transactions'];
        $totalTransactions = count($allTransactions);
        $itemsOnFirstPage = 25; 
        $itemsOnSubsequentPages = 35;

        $transactionPages = 0;
        $addExtraSummaryPage = false;
        $totalPages = 0;

        if ($totalTransactions > 0) {
            if ($totalTransactions <= $itemsOnFirstPage) {
                $transactionPages = 1;
                $itemsOnLastTxnPage = $totalTransactions;
            } else {
                $remainingItems = $totalTransactions - $itemsOnFirstPage;
                $transactionPages = 1 + ceil($remainingItems / $itemsOnSubsequentPages);
                $itemsOnLastTxnPage = $remainingItems % $itemsOnSubsequentPages;
                if ($itemsOnLastTxnPage == 0) {
                    $itemsOnLastTxnPage = $itemsOnSubsequentPages;
                }
            }
            
            if ($itemsOnLastTxnPage > 25) {
                $addExtraSummaryPage = true;
            }

            $totalPages = $transactionPages;
            if ($addExtraSummaryPage) {
                $totalPages++;
            }
        } else {
            $totalPages = 1;
        }
    @endphp

    @for ($currentPage = 1; $currentPage <= $totalPages; $currentPage++)
        @php
            $isLastPageOfLoop = ($currentPage == $totalPages);
            $isSummaryOnlyPage = $addExtraSummaryPage && $isLastPageOfLoop;
            $isLastTxnPage = ($currentPage == $transactionPages);
        @endphp
        
        <div>
            @if ($totalPages > 1)
                <div class="page-footer">
                    Page {{ $currentPage }} of {{ $totalPages }}
                </div>
            @endif

            @if ($currentPage == 1)
                <div class="company-name">{{ $data['company']['name'] ?? 'Company Name' }}</div>
                <div class="center-text">
                    {{ collect([$data['company']['address_line_1'], $data['company']['address_line_2'], $data['company']['city'], $data['company']['state'] . ' - ' . $data['company']['pincode']])->filter()->implode(', ') }}
                </div>
                <div class="center-text">GSTIN: {{ $data['company']['gst_number'] ?? '' }}</div>
                <div class="divider"></div>
                <div class="report-title">Sales Report</div>
                @if(isset($data['party']) && $data['party'])
                    <div class="center-text">Party: {{ $data['party']['name'] }}</div>
                   <div class="center-text">
                        @php
                            $partyAddress = collect([
                                $data['party']['address_line_1'] ?? null,
                                $data['party']['address_line_2'] ?? null,
                                $data['party']['city'] ?? null,
                                ($data['party']['state'] ?? '') . ' - ' . ($data['party']['pincode'] ?? '')
                            ])->filter()->implode(', ');
                        @endphp
                        {{ $partyAddress }}
                    </div>
                    <div class="center-text">GSTIN: {{ $data['party']['gst_number'] ?? '' }}</div>
                @else
                    <div class="center-text">All Parties</div>
                @endif
                <div class="center-text">
                    Date Range: From {{ \Carbon\Carbon::parse($data['date_range']['from'])->format('d/m/Y') }} 
                    to {{ \Carbon\Carbon::parse($data['date_range']['to'])->format('d/m/Y') }}
                </div>
                <div class="center-text bold">Item: {{ $data['item']['name'] ?? 'All Items' }}</div>
            @endif

            @if ($isSummaryOnlyPage)
                 <div class="report-title" style="margin-top: 50px;"></div>
            @else
                @php
                    $offset = ($currentPage == 1) ? 0 : $itemsOnFirstPage + ($currentPage - 2) * $itemsOnSubsequentPages;
                    $limit = ($currentPage == 1) ? $itemsOnFirstPage : $itemsOnSubsequentPages;
                    $chunk = array_slice($allTransactions, $offset, $limit);
                @endphp
                <table class="table">
                    <thead class="table-header">
                        <tr>
                            <th class="header-cell" style="width: 10%;">Date</th>
                            <th class="header-cell" style="width: 8%;">Voucher</th>
                            <th class="header-cell" style="width: 30%;">Item</th>
                            @if ($showRateColumns)<th class="header-cell" style="width: 10%;">Rate</th>@endif
                            <th class="header-cell" style="width: 8%;">Qty</th>
                            <th class="header-cell" style="width: 10%;">Remark</th>
                            @if ($showGstColumn)<th class="header-cell" style="width: 7%;">GST %</th>@endif
                            @if ($showRateColumns)<th class="header-cell" style="width: 17%;">Total Price</th>@endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($chunk as $transaction)
                            {{-- This is the corrected line --}}
                            <tr class="{{ $loop->iteration % 2 === 0 ? 'striped-dark' : 'striped-light' }}">
                                <td class="cell text-center">{{ \Carbon\Carbon::parse($transaction['transaction_date'])->format('d/m/Y') }}</td>
                                <td class="cell text-center">{{ $transaction['voucher_no'] }}</td>
                                <td class="cell text-center">{{ $transaction['item_name'] }}</td>
                                @if ($showRateColumns)<td class="cell text-center">{{ number_format($transaction['job_work_rate'], 2) }}</td>@endif
                                <td class="cell text-center">{{ $transaction['quantity'] }}</td>
                                <td class="cell text-center">{{ $transaction['remark'] ?? '-' }}</td>
                                @if ($showGstColumn)<td class="cell text-center">{{ $transaction['gst_percent_rate'] ?? 0 }}%</td>@endif
                                @if ($showRateColumns)<td class="cell text-right">{{ number_format($transaction['total_price'], 2) }}</td>@endif
                            </tr>
                        @empty
                            @if ($totalTransactions == 0)
                                @php
                                    $colspan = 4;
                                    if ($showRateColumns) $colspan+=3;
                                    if (!$showGstColumn && $showRateColumns) $colspan--;
                                @endphp
                                <tr><td colspan="{{ $colspan }}" class="cell text-center">No transactions found for the selected period.</td></tr>
                            @endif
                        @endforelse

                        @if ($isLastTxnPage)
                            <tr class="footer-row">
                                <td colspan="{{ $showRateColumns ? 3 : 3 }}" class="cell bold">Grand Total</td>
                                @if ($showRateColumns)<td></td>@endif
                                <td class="cell text-center bold">{{ collect($allTransactions)->sum('quantity') }}</td>
                                @if ($showRateColumns)
                                    <td colspan="{{ $showGstColumn ? 2 : 1 }}"></td>
                                    <td class="cell text-right bold">{{ number_format($data['summary']['total_sales'], 2) }}</td>
                                @else
                                    <td></td>
                                @endif
                            </tr>
                        @endif
                    </tbody>
                </table>
            @endif

            @if (($isLastTxnPage && !$addExtraSummaryPage) || $isSummaryOnlyPage)
                @if($totalTransactions > 0 || $currentPage == 1)
                <div class="summary-box">
                    <div class="report-title">Report Summary</div>
                    <table class="summary-table" style="width:100%;">
                         <tr>
                            @if ($showRateColumns)
                                <td><strong>Total Sales Value:</strong> {{ number_format($data['summary']['total_sales'], 2) }}</td>
                            @else
                                <td></td>
                            @endif
                            <td><strong>Total Transactions:</strong> {{ $data['summary']['transaction_count'] ?? 0 }}</td>
                        </tr>
                        <tr>
                            <td><strong>Date Range:</strong> {{ \Carbon\Carbon::parse($data['date_range']['from'])->format('d/m/Y') }} - {{ \Carbon\Carbon::parse($data['date_range']['to'])->format('d/m/Y') }}</td>
                            <td><strong>Total Quantity Sold:</strong> {{ collect($allTransactions)->sum('quantity') }}</td>
                        </tr>
                        @if (($data['userSettings']['jobwork_inhouse_mr'] ?? 'yes') !== 'no')
                        <tr>
                            <td><strong>MR Transactions:</strong> {{ $data['summary']['transaction_mr'] ?? 0 }}</td>
                            <td><strong>MR Qty:</strong> {{ $data['summary']['transaction_mr_qty'] ?? 0 }}</td>
                        </tr>
                        @endif
                        @if (($data['userSettings']['jobwork_inhouse_cr'] ?? 'yes') !== 'no')
                        <tr>
                            <td><strong>CR Transactions:</strong> {{ $data['summary']['transaction_cr'] ?? 0 }}</td>
                            <td><strong>CR Qty:</strong> {{ $data['summary']['transaction_cr_qty'] ?? 0 }}</td>
                        </tr>
                        @endif
                        @if (($data['userSettings']['jobwork_inhouse_bh'] ?? 'yes') !== 'no')
                        <tr>
                            <td><strong>BH Transactions:</strong> {{ $data['summary']['transaction_bh'] ?? 0 }}</td>
                            <td><strong>BH Qty:</strong> {{ $data['summary']['transaction_bh_qty'] ?? 0 }}</td>
                        </tr> 
                        @endif
                        <tr>
                            <td><strong>OK Transactions:</strong> {{ $data['summary']['transaction_ok'] ?? 0 }}</td>
                            <td><strong>OK Qty:</strong> {{ $data['summary']['transaction_ok_qty'] ?? 0 }}</td>
                        </tr>
                        <tr>
                            <td><strong>As-It-Is Transactions:</strong> {{ $data['summary']['transaction_asitis'] ?? 0 }}</td>
                            <td><strong>As-It-Is Qty:</strong> {{ $data['summary']['transaction_asitis_qty'] ?? 0 }}</td>
                        </tr>
                    </table>
                </div>
                @endif
            @endif
        </div>

        @if (!$isLastPageOfLoop)
            <div class="page-break"></div>
        @endif
    @endfor
</body>
</html>
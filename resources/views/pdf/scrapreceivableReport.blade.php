<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Scrap Receivable Report</title>
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
        .summary-table td { font-size: 10px; padding: 3px 5px; }
        .page-break { page-break-after: always; }
        .page-footer {
            position: fixed;
            bottom: 10px;
            right: 20px;
            font-size: 14px;
        }
        .striped-light { background-color: #ffffff; }
        .striped-dark { background-color: #f9f9f9; }
    </style>
</head>
<body>
    @php
        // --- Settings Flags ---
        $showValues = ($data['userSettings']['include_values_in_scrap_return'] ?? 'yes') !== 'no';

        // --- Pagination Logic ---
        $allRecords = $data['records'];
        $totalRecords = count($allRecords);
        $itemsOnFirstPage = 25; 
        $itemsOnSubsequentPages = 35;

        $recordPages = 0;
        $addExtraSummaryPage = false;
        $totalPages = 0;

        if ($totalRecords > 0) {
            if ($totalRecords <= $itemsOnFirstPage) {
                $recordPages = 1;
                $itemsOnLastPage = $totalRecords;
            } else {
                $remainingItems = $totalRecords - $itemsOnFirstPage;
                $recordPages = 1 + ceil($remainingItems / $itemsOnSubsequentPages);
                $itemsOnLastPage = $remainingItems % $itemsOnSubsequentPages;
                if ($itemsOnLastPage == 0) $itemsOnLastPage = $itemsOnSubsequentPages;
            }
            
            if ($itemsOnLastPage > 25) {
                $addExtraSummaryPage = true;
            }

            $totalPages = $recordPages;
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
            $isLastRecordPage = ($currentPage == $recordPages);
        @endphp
        
        <div>
            @if ($totalPages > 1)
                <div class="page-footer">
                    Page {{ $currentPage }} of {{ $totalPages }}
                </div>
            @endif

            @if ($currentPage == 1)
                {{-- Report Header --}}
                <div class="company-name">{{ $data['company']['name'] ?? 'Company Name' }}</div>
                <div class="center-text">
                    {{ collect([$data['company']['address_line_1'], $data['company']['address_line_2'], $data['company']['city'], $data['company']['state'] . ' - ' . $data['company']['pincode']])->filter()->implode(', ') }}
                </div>
                <div class="center-text">GSTIN: {{ $data['company']['gst_number'] ?? '' }}</div>
                <div class="divider"></div>
                <div class="report-title">Scrap Return Report</div>

                @if(isset($data['party']) && $data['party'])
                    <div class="center-text">Party: {{ $data['party']['name'] }}</div>
                    <div class="center-text">
                        {{ collect([$data['party']['address_line_1'], $data['party']['address_line_2'], $data['party']['city'], ($data['party']['state'] ?? '') . ' - ' . ($data['party']['pincode'] ?? '')])->filter()->implode(', ') }}
                    </div>
                    <div class="center-text">GSTIN: {{ $data['party']['gst_number'] ?? '' }}</div>
                @else
                    <div class="center-text">All Parties</div>
                @endif

                <div class="center-text">
                    Date Range: From {{ \Carbon\Carbon::parse($data['date_range']['from'])->format('d/m/Y') }} 
                    to {{ \Carbon\Carbon::parse($data['date_range']['to'])->format('d/m/Y') }}
                </div>
                <div class="center-text bold">
                    Item: {{ $data['item']['name'] ?? 'All Items' }} ({{ $data['item']['code'] ?? 'N/A' }})
                </div>
            @endif

            @if (!$isSummaryOnlyPage)
                @php
                    $offset = ($currentPage == 1) ? 0 : $itemsOnFirstPage + ($currentPage - 2) * $itemsOnSubsequentPages;
                    $limit = ($currentPage == 1) ? $itemsOnFirstPage : $itemsOnSubsequentPages;
                    $chunk = array_slice($allRecords, $offset, $limit);
                @endphp
                <table class="table">
                    <thead class="table-header">
                        <tr>
                            <th class="header-cell" style="width: 10%;">Date</th>
                            <th class="header-cell" style="width: 13%;">Voucher No</th>
                            <th class="header-cell">Voucher Type</th>
                            <th class="header-cell" style="width: 8%;">Qty</th>
                            <th class="header-cell" style="width: 12%;">Remark</th>
                            @if ($showValues)
                            <th class="header-cell" style="width: 12%;">Scrap Receivable(Kg)</th>
                            <th class="header-cell" style="width: 12%;">Scrap Taken(Kg)</th>
                            <th class="header-cell" style="width: 12%;">Balance</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($chunk as $record)
                            <tr class="{{ $loop->iteration % 2 === 0 ? 'striped-dark' : 'striped-light' }} {{ $record['voucher_type'] == 'opening' ? 'bold' : '' }}">
                                <td class="cell text-center">{{ \Carbon\Carbon::parse($record['date'])->format('d/m/Y') }}</td>
                                <td class="cell text-center">{{ $record['voucher_no'] }}</td>
                                <td class="cell text-center">{{ $record['voucher_type'] }}</td>
                                <td class="cell text-center">{{ $record['quantity'] }}</td>
                                <td class="cell text-center">{{ $record['remark'] ?? '-' }}</td>
                                @if ($showValues)
                                <td class="cell text-right">{{ number_format($record['scrap_receivable'], 3) }}</td>
                                <td class="cell text-right">{{ number_format($record['scrap_taken'], 3) }}</td>
                                <td class="cell text-right">{{ number_format($record['balance'], 3) }}</td>
                                @endif
                            </tr>
                        @empty
                            @if ($totalRecords == 0)
                                <tr><td colspan="{{ $showValues ? 8 : 5 }}" class="cell text-center">No records found for the selected period.</td></tr>
                            @endif
                        @endforelse

                        @if ($isLastRecordPage)
                            <tr class="footer-row">
                                <td colspan="3" class="cell bold text-center">Grand Total</td>
                                <td class="cell text-center bold">{{ collect($allRecords)->where('id', '!=', 'opening-balance')->sum('quantity') }}</td>
                                <td></td>
                                @if ($showValues)
                                <td class="cell text-right bold">{{ number_format($data['summary']['scrap_receivable'], 3) }}</td>
                                <td class="cell text-right bold">{{ number_format($data['summary']['scrap_taken'], 3) }}</td>
                                <td class="cell text-right bold">{{ number_format($data['summary']['scrap_balance'], 3) }}</td>
                                @endif
                            </tr>
                        @endif
                    </tbody>
                </table>
            @endif

            @if (($isLastRecordPage && !$addExtraSummaryPage) || $isSummaryOnlyPage)
                @if($totalRecords > 0)
                <div class="summary-box">
                    <div class="summary-title">Report Summary</div>
                    <table class="summary-table" style="width:100%;">
                         @if ($showValues)
                         <tr>
                            <td><strong>Total Scrap Receivable:</strong> {{ number_format($data['summary']['scrap_receivable'], 3) }}</td>
                            <td><strong>Total Scrap Taken:</strong> {{ number_format($data['summary']['scrap_taken'], 3) }}</td>
                         </tr>
                         <tr>
                            <td><strong>Final Scrap Balance:</strong> {{ number_format($data['summary']['scrap_balance'], 3) }}</td>
                            <td><strong>Total Transactions:</strong> {{ $data['summary']['transaction_count'] ?? 0 }}</td>
                         </tr>
                         @endif
                         @if (($data['userSettings']['scrap_outsourcing_mr'] ?? 'no') === 'yes')
                         <tr>
                            <td><strong>MR Transactions:</strong> {{ $data['summary']['transaction_mr'] ?? 0 }}</td>
                            <td><strong>MR Qty:</strong> {{ $data['summary']['transaction_mr_qty'] ?? 0 }}</td>
                         </tr>
                         @endif
                         @if (($data['userSettings']['scrap_outsourcing_cr'] ?? 'no') === 'yes')
                         <tr>
                            <td><strong>CR Transactions:</strong> {{ $data['summary']['transaction_cr'] ?? 0 }}</td>
                            <td><strong>CR Qty:</strong> {{ $data['summary']['transaction_cr_qty'] ?? 0 }}</td>
                         </tr>
                         @endif
                         @if (($data['userSettings']['scrap_outsourcing_bh'] ?? 'no') === 'yes')
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

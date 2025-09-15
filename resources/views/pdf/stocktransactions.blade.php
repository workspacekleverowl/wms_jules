<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Stock Balance Report</title>
    <style>
        @page {
            margin: 20px;
            font-family: 'DejaVu Sans', sans-serif;
        }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 14px;
            line-height: 1.4;
            color: #000;
        }
        .company-name {
            font-size: 18px;
            text-align: center;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .company-address {
            font-size: 14px;
            text-align: center;
            margin-bottom: 5px;
        }
        .divider {
            border-bottom: 1px solid black;
            margin: 0 150px 5px 150px;
        }
        .report-title {
            font-size: 14px;
            text-align: center;
            font-weight: bold;
            margin: 5px 0;
        }
        .report-info {
            font-size: 14px;
            font-weight: bold;
            text-align: center;
            margin-bottom: 10px;
        }
        .center-text {
            text-align: center;
            font-size: 14px;
            margin-bottom: 2px;
        }
        .party-info {
            margin: 10px 0;
            display: flex;
            justify-content: space-between;
        }
        .party-box {
            width: 48%;
            border: 1px solid #ddd;
            padding: 8px;
        }
        .party-title {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .party-detail {
            font-size: 14px;
            margin-bottom: 2px;
        }
        .summary-box {
            margin: 10px 0 15px 0;
            border: 1px solid #ddd;
            padding: 8px;
        }
        .summary-title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .summary-content {
            display: flex;
            flex-wrap: wrap;
        }
        .summary-item {
            width: 33%;
            margin-bottom: 5px;
        }
        .summary-label {
            font-size: 10px;
        }
        .summary-value {
            font-size: 10px;
            font-weight: bold;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        .table-header {
            background-color: #f2f2f2;
            border-top: 1px solid black;
            border-bottom: 1px solid black;
        }
        .table-row {
            border-bottom: 0.5px solid #bbb;
            min-height: 20px;
        }
        .table-row.striped-light {
            background-color: #ffffff;
        }
        .table-row.striped-dark {
            background-color: #f2f2f2;
        }
        .table th, .table td {
            padding: 4px 3px;
            vertical-align: top;
        }
        .date-col { width: 10%; }
        .voucher-col { width: 8%; text-align: center; }
        .type-col { width: 25%; text-align: center; }
        .remark-col { width: 8%; text-align: center; }
        .rate-col { width: 10%; text-align: center; }
        .inward-col { width: 8%; text-align: center; }
        .outward-col { width: 8%; text-align: center; }
        .balance-col { width: 10%; text-align: center; }
        .header-cell {
            font-weight: bold;
            font-size: 12px;
        }
        .cell {
            font-size: 12px;
        }
        .footer-row {
            background-color: #f2f2f2;
            border-top: 1px solid black;
            border-bottom: 1px solid black;
        }
        .footer-label {
            font-weight: bold;
            font-size: 14px;
        }
        .footer-value {
            font-weight: bold;
            font-size: 14px;
            text-align: center;
        }
        .signature {
            margin-top: 30px;
            display: flex;
            justify-content: space-between;
        }
        .signature-box {
            width: 150px;
        }
        .signature-line {
            border-top: 1px solid black;
            margin: 40px 0 5px 0;
        }
        .signature-text {
            font-size: 14px;
            text-align: center;
        }
        .bold {
            font-weight: bold;
        }
        .page-break {
            page-break-after: always;
        }
        .page-footer {
            position: fixed;
            bottom: 10px;
            right: 20px;
            font-size: 14px;
        }
    </style>
</head>
<body>
    @php
        $allTransactions = $data['transactions'];
        $totalTransactions = count($allTransactions);
        $itemsFirstPage = 13;
        $itemsPerPage = 19;
        $pagedTransactions = [];

        if ($totalTransactions > 0) {
            // Take the first chunk for the first page
            $pagedTransactions[] = array_slice($allTransactions, 0, $itemsFirstPage);
            
            // Get the remaining transactions
            $remainingTransactions = array_slice($allTransactions, $itemsFirstPage);

            // Chunk the rest for subsequent pages
            if (!empty($remainingTransactions)) {
                $pagedTransactions = array_merge($pagedTransactions, array_chunk($remainingTransactions, $itemsPerPage));
            }
        }
        
        $totalPages = count($pagedTransactions);
    @endphp

    @foreach ($pagedTransactions as $pageIndex => $transactionsOnPage)
        <div class="{{ $pageIndex + 1 < $totalPages ? 'page-break' : '' }}">
            
            @if ($totalPages > 1)
                <div class="page-footer">
                    Page {{ $pageIndex + 1 }} of {{ $totalPages }}
                </div>
            @endif

            @if ($pageIndex == 0)
                <div class="company-name">{{ $data['company']['name'] ?? 'Company Name' }}</div>
                <div class="center-text">
                    @php
                        $companyAddress = collect([
                            $data['company']['address_line_1'] ?? null,
                            $data['company']['address_line_2'] ?? null,
                            ($data['company']['city'] ?? '') . ', ' . ($data['company']['state'] ?? '') . ' - ' . ($data['company']['pincode'] ?? '')
                        ])->filter()->implode(', ');
                    @endphp
                    {{ $companyAddress }}
                </div>
                <div class="center-text">GSTIN: {{ $data['company']['gst_number'] ?? '' }}</div>
                <div class="divider"></div>
                <div class="report-title">Stock Balance Report</div>

                @if(isset($data['party']) && $data['party'])
                    <div class="company-address">Party: {{ $data['party']['name'] }}</div>
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

                <div class="report-info">
                    @if(isset($data['item']) && $data['item'])
                        Item: {{ $data['item']['name'] }} ({{ $data['item']['code'] }})
                    @else
                        All Items
                    @endif
                </div>
            @endif

            <table class="table">
                <thead class="table-header">
                    <tr>
                        <th class="date-col header-cell">Date</th>
                        <th class="voucher-col header-cell">Voucher</th>
                        <th class="type-col header-cell">Particulars</th>
                        <th class="inward-col header-cell">Inward</th>
                        <th class="outward-col header-cell">Outward</th>
                        <th class="remark-col header-cell">Remark</th>
                        <th class="balance-col header-cell">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($pageIndex == 0)
                        <tr class="table-row">
                            <td class="date-col cell bold">
                                {{ \Carbon\Carbon::parse($data['date_range']['from'])->format('d/m/Y') }}
                            </td>
                            <td colspan="5" class="cell bold">Opening Balance</td>
                            <td class="balance-col cell bold">{{ $data['summary']['opening_balance'] }}</td>
                        </tr>
                    @endif

                    @foreach ($transactionsOnPage as $tIndex => $transaction)
                        <tr class="table-row {{ $tIndex % 2 === 0 ? 'striped-light' : 'striped-dark' }}">
                            <td class="date-col cell">
                                {{ \Carbon\Carbon::parse($transaction['transaction_date'])->format('d/m/Y') }}
                            </td>
                            <td class="voucher-col cell">{{ $transaction['voucher_no'] }}</td>
                            <td class="type-col cell">{{ $transaction['transaction_type'] == 'inward' ? 'Inward from' : 'Outward to' }} {{ $transaction['party_name'] }}</td>
                            <td class="inward-col cell">{{ $transaction['inward_quantity'] }}</td>
                            <td class="outward-col cell">{{ $transaction['outward_quantity'] }}</td>
                            <td class="remark-col cell">{{ $transaction['remark'] ?? '-' }}</td>
                            <td class="balance-col cell">{{ $transaction['running_balance'] }}</td>
                        </tr>
                    @endforeach

                    @if ($pageIndex + 1 == $totalPages)
                        <tr class="footer-row">
                            <td class="date-col footer-label"></td>
                            <td class="voucher-col footer-label"></td>
                            <td class="type-col footer-label">Total</td>
                            <td class="inward-col footer-value">
                                {{ collect($data['transactions'])->sum('inward_quantity') }}
                            </td>
                            <td class="outward-col footer-value">
                                {{ collect($data['transactions'])->sum('outward_quantity') }}
                            </td>
                            <td class="remark-col footer-label"></td>
                            <td class="balance-col footer-value">{{ $data['summary']['closing_balance'] }}</td>
                        </tr>
                    @endif
                </tbody>
            </table>

            @if ($pageIndex + 1 == $totalPages)
                <div class="summary-box">
                    <div class="summary-title">Summary</div>
                    <div class="summary-content">
                        <table style="width:100%; border-collapse: collapse; font-size: 12px;">
                            <tr>
                                <td>Opening Balance: <b>{{ $data['summary']['opening_balance'] ?? 0 }}</b></td>
                                <td>Total Inward: <b>{{ $data['summary']['total_inward'] ?? 0 }}</b></td>
                                <td>Total Outward: <b>{{ $data['summary']['total_outward'] ?? 0 }}</b></td>
                            </tr>
                            <tr>
                                <td>Closing Balance: <b>{{ $data['summary']['closing_balance'] ?? 0 }}</b></td>
                                <td>Transactions: <b>{{ $data['summary']['transaction_count'] ?? 0 }}</b></td>
                                <td>MR Transactions: <b>{{ $data['summary']['transaction_mr'] ?? 0 }}</b></td>
                            </tr>
                            <tr>
                                <td>CR Transactions: <b>{{ $data['summary']['transaction_cr'] ?? 0 }}</b></td>
                                <td>BH Transactions: <b>{{ $data['summary']['transaction_bh'] ?? 0 }}</b></td>
                                <td>OK Transactions: <b>{{ $data['summary']['transaction_ok'] ?? 0 }}</b></td>
                            </tr>
                            <tr>
                                <td>As It Is Transactions: <b>{{ $data['summary']['transaction_asitis'] ?? 0 }}</b></td>
                                <td>MR Quantity: <b>{{ $data['summary']['transaction_mr_qty'] ?? 0 }}</b></td>
                                <td>CR Quantity: <b>{{ $data['summary']['transaction_cr_qty'] ?? 0 }}</b></td>
                            </tr>
                            <tr>
                                <td>BH Quantity: <b>{{ $data['summary']['transaction_bh_qty'] ?? 0 }}</b></td>
                                <td>OK Quantity: <b>{{ $data['summary']['transaction_ok_qty'] ?? 0 }}</b></td>
                                <td>As It Is Quantity: <b>{{ $data['summary']['transaction_asitis_qty'] ?? 0 }}</b></td>
                            </tr>
                        </table>
                    </div>
                </div>

                <div class="signature">
                    <table style="width:100%; border-collapse: collapse;">
                        <tr>
                            <td class="signature-box">
                                <div class="signature-line"></div>
                                <div class="signature-text">Prepared By</div>
                            </td>
                            <td style="width: 50%;"></td> <td class="signature-box">
                                <div class="signature-line"></div>
                                <div class="signature-text">Approved By</div>
                            </td>
                        </tr>
                    </table>
                </div>
            @endif
        </div>
    @endforeach
</body>
</html>
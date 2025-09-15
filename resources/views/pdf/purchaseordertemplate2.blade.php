<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Purchase Order Invoice</title>
    <style>
        @page { 
            size: A4;
            margin: 8mm;
        }

        body {
            font-family: 'Helvetica', Arial, sans-serif;
            font-size: 14px;
            margin: 0;
            line-height: 1.3;
            color: #000;
        }

        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-bottom: 10px;
        }
        
        th, td { 
            padding: 8px; 
            vertical-align: top; 
            border: none;
        }

        .header-section {
            width: 100%;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e0e0e0;
        }

        .invoice-title {
            font-size: 18px;
            font-weight: bold;
            color: #2563eb;
            margin: 0;
        }

        .company-info {
            text-align: right;
            font-size: 12px;
        }

        .company-name { 
            font-size: 14px; 
            font-weight: bold; 
            color: #2563eb; 
            margin-bottom: 5px;
        }

        .invoice-details {
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e0e0e0;
        }

        .detail-label { font-size: 12px; color: #666; margin-bottom: 2px; }
        .detail-value { font-size: 12px; font-weight: bold; color: #2563eb; }

        .address-section { margin-bottom: 20px; }
        .address-box { margin-right: 15px; padding: 10px; background-color: #f8f9fa; border: 1px solid #e0e0e0; }
        .address-title { font-size: 12px; font-weight: bold; color: #2563eb; margin-bottom: 8px; }
        .address-text { font-size: 12px; line-height: 1.4; margin-bottom: 3px; }

        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .items-table th { background-color: #f1f5f9; padding: 8px; border-bottom: 1px solid #cbd5e1; font-weight: bold; font-size: 12px; color: #2563eb; text-align: center; }
        .items-table td { padding: 8px; border-bottom: 0.5px solid #e2e8f0; font-size: 12px;text-align: center; }

        .col-center { text-align: center; }
        .col-right { text-align: right; }

        .total-table { width: 200px; border: 1px solid #e0e0e0; }
        .total-row { display: table-row; padding: 5px; border-bottom: 0.5px solid #e0e0e0; }
        .total-row-final { display: table-row; padding: 8px; background-color: #f1f5f9; border-top: 1px solid #2563eb; }

        .grand-total-label { font-size: 14px; font-weight: bold; color: #2563eb; }
        .grand-total-value { font-size: 14px; text-align: right; font-weight: bold; color: #2563eb; }

        .signature { margin-top: 45px; text-align: center; }
        .signature-line { width: 150px; height: 1px; background-color: #000; margin: 0 auto 5px auto; }

        .page-number { position: absolute; bottom: 20px; right: 20px; font-size: 12px; }
        .page-break { page-break-before: always; }
        .no-break { page-break-inside: avoid; }
        .blank-row td { height: 35px; border-bottom: 0.5px solid #e2e8f0; }
    </style>
</head>
<body>

@php
    // --- Start: Pagination Logic from Sales Template ---
    $items = $order->bkorderlineitems;
    $perPage = 7;
    $totalItems = count($items);

    $fullPages = intdiv($totalItems, $perPage);
    $remaining = $totalItems % $perPage;

    $pages = [];

    if ($totalItems == 0) {
        $pages[] = [];
    } else {
        for ($i = 0; $i < $fullPages; $i++) {
            $pages[] = $items->slice($i * $perPage, $perPage);
        }
        if ($remaining > 0) {
            $pages[] = $items->slice($fullPages * $perPage, $remaining);
        }
    }

    $lastPageItems = count(end($pages));

    if ($lastPageItems >= 4) {
        $pages[] = collect([]); // footer-only page
    }

    $totalPages = count($pages);
    
    // Assuming $orderGst is passed instead of $invoiceGst
    // Assuming $bankDetails is passed for bank info
    // Assuming $includeTax is passed to control GST column
    $hasLineItemDiscount = $order->bkorderlineitems->some(fn($item) => isset($item->line_item_discount) && $item->line_item_discount > 0);
    // --- End: Pagination Logic ---
@endphp

@foreach($pages as $pageIndex => $pageItems)
    @php
        $page = $pageIndex + 1;
        $isFooterPage = ($pageIndex == $totalPages - 1 && count($pageItems) == 0);
        $showFooter = (!$isFooterPage && $page == $totalPages) || $isFooterPage;
    @endphp

    @if($page > 1)
        <div class="page-break"></div>
    @endif

    <div class="page-container no-break">
        {{-- HEADER --}}
        <div class="header-section">
            <div class="invoice-title">PURCHASE ORDER</div>
            <div class="company-info">
                {{-- This is the buyer's company info --}}
                <div class="company-name">{{ $order->company->company_name }}</div>
                <div class="address-text">{{ $order->company->address1 }}</div>
                @if($order->company->address2)
                    <div class="address-text">{{ $order->company->address2 }}</div>
                @endif
                <div class="address-text">{{ $order->company->city }}, {{ $order->company->state->title }} {{ $order->company->pincode }}</div>
                <div class="address-text">GST: {{ $order->company->gst_number ?? 'N/A' }}</div>
            </div>
        </div>

        {{-- INVOICE DETAILS --}}
        <table class="invoice-details">
            <tr>
                <td class="detail-label">Order No:</td>
                <td class="detail-label">Order Date:</td>
                <td class="detail-label">Financial Year:</td>
                <td class="detail-label">Payment Terms:</td>
            </tr>
            <tr>
                <td class="detail-value">{{ $order->order_no }}</td>
                <td class="detail-value">{{ \Carbon\Carbon::parse($order->order_date)->format('d/m/Y') }}</td>
                <td class="detail-value">{{ $order->financial_year }}</td>
                <td class="detail-value">{{ $order->payment_terms }}</td>
            </tr>
        </table>

        {{-- ADDRESSES --}}
        <table class="address-section">
            <tr>
                <td class="address-box" style="width:50%;">
                    <div class="address-title">Bill From (Supplier):</div>
                    <div class="address-text">{{ $order->company_name_billing }}</div>
                    <div class="address-text">{{ $order->company_address_billing }}</div>
                    <div class="address-text">{{ $order->company_city_billing }}, {{ $order->company_state_name_billing }}</div>
                    <div class="address-text">PIN: {{ $order->company_pincode_billing }}</div>
                    <div class="address-text">GSTIN: {{ $order->company_GST_number_billing ?? 'N/A' }}</div>
                </td>
                <td class="address-box" style="width:50%;">
                    <div class="address-title">Ship To:</div>
                     {{-- Shipping to the buyer's main address unless specified otherwise --}}
                    @if($order->company_name_shipping)
                        <div class="address-text">{{ $order->company->company_name }}</div>
                        <div class="address-text">{{ $order->company->address1 }}</div>
                         @if($order->company->address2)
                            <div class="address-text">{{ $order->company->address2 }}</div>
                        @endif
                        <div class="address-text">{{ $order->company->city }}, {{ $order->company->state->title }} {{ $order->company->pincode }}</div>
                        <div class="address-text">GST: {{ $order->company->gst_number ?? 'N/A' }}</div>
                    @else
                        <div class="address-text">{{ $order->company->company_name }}</div>
                        <div class="address-text">{{ $order->company->address1 }}</div>
                        @if($order->company->address2)
                            <div class="address-text">{{ $order->company->address2 }}</div>
                        @endif
                        <div class="address-text">{{ $order->company->city }}, {{ $order->company->state->title }} {{ $order->company->pincode }}</div>
                    @endif
                </td>
            </tr>
        </table>

        {{-- ITEMS TABLE --}}
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 25%;">Item Description</th>
                    <th style="width: 10%;">HSN</th>
                    <th style="width: 8%;">Qty</th>
                    <th style="width: 12%;">Rate</th>
                    <th style="width: 8%;">Per</th>
                    @if($includeTax)
                        <th style="width: 8%;">GST</th>
                    @endif
                    @if($hasLineItemDiscount)
                        <th style="width: 12%;">Disc %</th>
                    @endif
                    <th style="width: 12%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($pageItems as $i => $item)
                    @php $serial = $pageIndex * $perPage + $i + 1; @endphp
                    <tr>
                        <td>{{ $item->product_name }}<br><span style="color:#666;">{{ $item->product_description ?? '' }}</span></td>
                        <td class="col-center">{{ $item->product_hsn ?? 'N/A' }}</td>
                        <td class="col-center">{{ $item->quantity }}</td>
                        <td class="col-center">{{ number_format($item->rate,2) }}</td>
                        <td class="col-center">{{ $item->unit }}</td>
                        @if($includeTax)
                            <td class="col-center">{{ $item->gst_rate }}%</td>
                        @endif
                        @if($hasLineItemDiscount)
                            <td class="col-center">{{ $item->line_item_discount ?? '0' }}%</td>
                        @endif
                        <td class="col-right">{{ number_format($item->amount_after_line_discount ?? $item->amount,2) }}</td>
                    </tr>
                @endforeach

                @if($isFooterPage)
                    @for($i=0;$i<3;$i++)
                        <tr class="blank-row">
                            <td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>
                            @if($includeTax)<td>&nbsp;</td>@endif
                            @if($hasLineItemDiscount)<td>&nbsp;</td>@endif
                            <td>&nbsp;</td>
                        </tr>
                    @endfor
                @endif
            </tbody>
        </table>

        {{-- FOOTER --}}
        @if($showFooter)
            <table style="width:100%; margin-top:20px;">
                <tr>
                    <td style="width:60%; vertical-align:top;">
                        @if($order->notes)
                            <div><b>Notes:</b> {{ $order->notes }}</br></br></div>
                        @endif
                        @if($order->terms_and_conditions)
                            <div><b>Terms:</b> {{ $order->terms_and_conditions }}</br></br></div>
                        @endif
                        @if($includeBankDetails && isset($bankDetails))
                            <div><b>Bank Details:</br></b> 
                            Bank: {{ $bankDetails['bank_name'] ?? '' }}<br>
                            A/C: {{ $bankDetails['account_no'] ?? '' }}<br>
                            IFSC: {{ $bankDetails['ifsc_code'] ?? '' }}<br>
                            Branch: {{ $bankDetails['branch'] ?? '' }}</div>
                        @endif
                    </td>
                    <td style="width:40%; vertical-align:top; text-align:right;">
                        <table class="total-table"  style="width:100%;">
                            <tr class="total-row"><td>Sub-Total:</td><td class="col-right">{{ $orderGst['total_taxable_amount'] }}</td></tr>
                            @if($orderGst['total_cgst'])<tr class="total-row"><td>C-GST:</td><td class="col-right">{{ $orderGst['total_cgst'] }}</td></tr>@endif
                            @if($orderGst['total_sgst'])<tr class="total-row"><td>S-GST:</td><td class="col-right">{{ $orderGst['total_sgst'] }}</td></tr>@endif
                            @if($orderGst['total_igst'])<tr class="total-row"><td>I-GST:</td><td class="col-right">{{ $orderGst['total_igst'] }}</td></tr>@endif
                            <tr class="total-row"><td>Round-Off:</td><td class="col-right">{{ $orderGst['round_off'] }}</td></tr>
                            <tr class="total-row-final"><td class="grand-total-label">Grand Total:</td><td class="grand-total-value">{{ $orderGst['total_with_gst'] }}</td></tr>
                        </table>
                        <div><b>Amount in words:</b> {{ ucwords(numberToWords($orderGst['total_with_gst'])) }}</br></br></div>
                        @if($includeTax)
                            <div><b>Tax in words:</b> {{ ucwords(numberToWords($orderGst['total_gst'])) }}</div>
                        @endif
                        <div class="signature">
                            <div>for {{ $order->company->company_name ?? $order->company_name_billing }}</div>
                            <div class="signature-line"></div>
                            <div><b>Authorised Signatory</b></div>
                        </div>
                    </td>
                </tr>
            </table>
        @endif

        {{-- PAGE NUMBER --}}
        @if($totalPages > 1)
            <div class="page-number">Page {{ $page }} of {{ $totalPages }}</div>
        @endif
    </div>
@endforeach

</body>
</html>
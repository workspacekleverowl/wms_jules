<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Purchase Order Invoice</title>
    <style>
        @page { 
            size: A4;
            margin: 8mm;

            @bottom-right {
                content: "Page " counter(page) " of " counter(pages);
                font-size: 10px;
            }
        }
        
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            margin: 0;
            line-height: 1.4;
        }

        table { 
            width: 100%; 
            border-collapse: collapse;
            margin: 0;
        }
        
        th, td { 
            border: 0.6px solid #000; 
            padding: 3px; 
            vertical-align: top;
            font-size: 11px;
        }
        
        th { 
            background: #f6f6f6; 
            font-weight: bold; 
        }

        .no-border td, .no-border th { 
            border: none; 
        }
        
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: bold; }

        /* Critical: Remove all conflicting page break rules */
        .page-break { 
            page-break-before: always; 
        }
        
        /* Prevent unwanted breaks */
        .header, .invoice-title {
            page-break-inside: avoid;
            page-break-after: avoid;
        }
        
        .footer-section {
            page-break-inside: avoid;
        }
        
        /* Title styling */
        .invoice-title {
            text-align: center;
            font-size: 16px;
            font-weight: bold;
            margin: 5px 0 10px 0;
        }
        
        .header {
            margin-bottom: 0;
        }
        
        /* Compact spacing */
        .items-section {
            margin: 0 0;
        }
        
        /* Page number */
        .page-number {
            position: fixed;
            bottom: 5mm;
            right: 8mm;
            font-size: 10px;
        }
        
    </style>
</head>
<body>

@php
    $items = $order->bkorderlineitems;
    $perPage = 7; // Items per page synchronized with sales template
    $totalItems = count($items);
    
    // Calculate pages based on the sales template logic
    if ($totalItems == 0) {
        $pages = 1;
    } else {
        $fullPages = floor($totalItems / $perPage);
        $remainingItems = $totalItems % $perPage;
        
        if ($remainingItems == 0) {
            $pages = $fullPages;
        } elseif ($remainingItems <= 3) {
            // If last page has 1-3 items, footer shows on same page
            $pages = $fullPages + 1;
        } else {
            // If last page has 4-7 items, footer goes to next page
            $pages = $fullPages + 2;
        }
    }
@endphp

@for($page = 1; $page <= $pages; $page++)
    
    {{-- Page break: ONLY between pages, not before first page --}}
    @if($page > 1)
        <div class="page-break"></div>
    @endif
    
    @php
        $start = ($page - 1) * $perPage;
        $pageItems = $items->slice($start, $perPage);
        $remainingItemsOnLastItemPage = $totalItems % $perPage;
        $lastItemPageNumber = $totalItems > 0 ? ceil($totalItems / $perPage) : 1;
        
        // Determine if this page should show items
        $showItems = ($page <= $lastItemPageNumber);
        
        // Determine if this page should show footer
        $showFooter = false;
        if ($totalItems == 0) {
            $showFooter = ($page == 1);
        } elseif ($remainingItemsOnLastItemPage <= 3 && $remainingItemsOnLastItemPage > 0) {
            // Footer shows on last item page if it has 1-3 items
            $showFooter = ($page == $lastItemPageNumber);
        } else {
            // Footer shows on the page after last item page if last page has 4-7 items
            $showFooter = ($page == $pages);
        }
        
        $hasLineItemDiscount = $items->some(function($item) {
            return isset($item->line_item_discount) && $item->line_item_discount > 0;
        });
    @endphp
    
    {{-- PURCHASE ORDER TITLE --}}
    <div class="invoice-title">Purchase Order</div>

    {{-- HEADER --}}
    <div class="header">
        <table>
            <tr>
                <td style="width:50%;">
                    <b>Vendor Details</b><br>
                    {{ $order->company_name_billing }}<br>
                    {{ $order->company_address_billing }}<br>
                    {{ $order->company_city_billing }}, {{ $order->company_state_name_billing }} - {{ $order->company_pincode_billing }}<br>
                    GSTIN: {{ $order->company_GST_number_billing ?? 'N/A' }}
                </td>
                <td style="width:25%;">
                    Order No: <b>{{ $order->order_no }}</b><br>
                    Financial Year: <b>{{ $order->financial_year }}</b>
                </td>
                <td style="width:25%;">
                    Date: <b>{{ \Carbon\Carbon::parse($order->order_date)->format('d-m-Y') }}</b><br>
                    Payment Terms: <b>{{ $order->payment_terms }}</b>
                </td>
            </tr>
        </table>
        
        <table class="no-border">
            <tr>
                <td style="width:50%; border-left:0.5px solid black; border-right:0.5px solid black;">
                    <b>Billing to</b><br>
                    {{ $order->company->company_name }} <br>
                    {{ $order->company->address1 }}<br>
                    @if($order->company->address2) {{ $order->company->address2 }}<br> @endif
                    {{ $order->company->city }}, {{ $order->company->state->title }} - {{ $order->company->pincode }}<br>
                    GSTIN: {{ $order->company->gst_number ?? 'N/A' }}
                </td>
                <td style="width:50%; border-right:0.5px solid black;">
                    Order Type: <b>{{ strtoupper($order->order_type) }}</b><br>
                    Payment Status: <b>{{ $order->payment_status }}</b><br>
                    Total Amount: <b>{{ $orderGst['total_with_gst'] }}</b>
                </td>
            </tr>
        </table>
    </div>

    {{-- ITEMS for current page --}}
    @if($showItems)
    <div class="items-section">
        <table>
            <thead>
                <tr>
                    <th class="center" style="width:5%;">Sl</th>
                    <th style="width:{{ $hasLineItemDiscount ? '30%' : '35%' }};">Description of Goods</th>
                    <th class="center" style="width:10%;">HSN</th>
                    <th class="center" style="width:8%;">Qty</th>
                    @if($includeTax)
                    <th class="center" style="width:8%;">GST %</th>
                    @endif
                    <th class="center" style="width:10%;">Rate</th>
                    <th class="center" style="width:5%;">Per</th>
                    @if($hasLineItemDiscount)
                    <th class="center" style="width:8%;">Disc %</th>
                    @endif
                    <th class="right" style="width:{{ $hasLineItemDiscount ? '8%' : '10%' }};">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($pageItems as $i => $item)
                    @php $serialNumber =  $i + 1; @endphp
                    <tr>
                        <td class="center">{{ $serialNumber }}</td>
                        <td>
                            <b>{{ \Illuminate\Support\Str::limit($item->product_name, 25, '...') }}</b><br>
                            {{ \Illuminate\Support\Str::limit($item->product_description ?? 'No description', 25, '...') }}
                        </td>
                        <td class="center">{{ \Illuminate\Support\Str::limit($item->product_hsn ?? 'N/A', 10, '') }}</td>
                        <td class="center">{{ $item->quantity }}</td>
                        @if($includeTax)
                        <td class="center">{{ $item->gst_rate }}%</td>
                        @endif
                        <td class="right">{{ number_format($item->rate, 2) }}</td>
                        <td class="center">{{ $item->unit }}</td>
                        @if($hasLineItemDiscount)
                            <td class="center">{{ $item->line_item_discount ?? '0' }}%</td>
                        @endif
                        <td class="right">{{ number_format($item->amount_after_line_discount ?? $item->amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    {{-- Show blank rows on footer-only page --}}
    @if(!$showItems && $showFooter)
    <div class="items-section">
        <table>
            <thead>
                <tr>
                    <th class="center" style="width:5%;">Sl</th>
                    <th style="width:{{ $hasLineItemDiscount ? '30%' : '35%' }};">Description of Goods</th>
                    <th class="center" style="width:10%;">HSN</th>
                    <th class="center" style="width:8%;">Qty</th>
                    @if($includeTax)
                    <th class="center" style="width:8%;">GST %</th>
                    @endif
                    <th class="center" style="width:10%;">Rate</th>
                    <th class="center" style="width:5%;">Per</th>
                    @if($hasLineItemDiscount)
                    <th class="center" style="width:8%;">Disc %</th>
                    @endif
                    <th class="right" style="width:{{ $hasLineItemDiscount ? '8%' : '10%' }};">Amount</th>
                </tr>
            </thead>
            <tbody>
                {{-- Show 3 blank rows --}}
                @for ($j = 0; $j < 3; $j++)
                <tr>
                    <td class="center">&nbsp;</td>
                    <td>&nbsp;</td>
                    <td class="center">&nbsp;</td>
                    <td class="center">&nbsp;</td>
                    @if($includeTax)
                    <td class="center">&nbsp;</td>
                    @endif
                    <td class="right">&nbsp;</td>
                    <td class="center">&nbsp;</td>
                    @if($hasLineItemDiscount)
                        <td class="center">&nbsp;</td>
                    @endif
                    <td class="right">&nbsp;</td>
                </tr>
                @endfor
            </tbody>
        </table>
    </div>
    @endif

    {{-- FOOTER - Show based on logic --}}
    @if($showFooter)
        <div class="footer-section">
            {{-- Summary --}}
            <table>
                @if(isset($order->order_discount) && $order->order_discount > 0)
                <tr class="no-border">
                    <td style="border:0.5px solid black ;border-top: none">Overall Discount</td>
                    <td class="right" style="border-right: 0.5px solid black; border-bottom: 0.5px solid black;">{{ $order->order_discount }}%</td>
                </tr>
                @endif
                <tr class="no-border">
                    <td style="border-left: 0.5px solid black; border-right: 0.5px solid black;">Sub-Total</td>
                    <td class="right" style="border-right: 0.5px solid black;">{{ $orderGst['total_taxable_amount'] }}</td>
                </tr>
                
                @if($includeTax)
                    @if($orderGst['total_cgst'])
                    <tr><td>C-GST</td><td class="right">{{ $orderGst['total_cgst'] }}</td></tr>
                    @endif
                    @if($orderGst['total_sgst'])
                    <tr><td>S-GST</td><td class="right">{{ $orderGst['total_sgst'] }}</td></tr>
                    @endif
                    @if($orderGst['total_igst'])
                    <tr><td>I-GST</td><td class="right">{{ $orderGst['total_igst'] }}</td></tr>
                    @endif
                @endif
                @if(isset($orderGst['round_off']) && $orderGst['round_off'] != 0)
                    <tr>
                        <td>Round-Off</td>
                        <td class="right">{{ $orderGst['round_off'] }}</td>
                    </tr>
                @endif
                
                <tr class="bold">
                    <td>Grand Total</td>
                    <td class="right">{{ $orderGst['total_with_gst'] }}</td>
                </tr>
            </table>

            {{-- Amount in words --}}
            <table>
                <tr class="no-border" style="border-right: 0.5px solid black; border-left: 0.5px solid black;">
                    <td>Amount in words: <b>{{ $includeTax ? ucwords(numberToWords($orderGst['total_with_gst'])) : ucwords(numberToWords($orderGst['total_taxable_amount'])) }}</b></td>
                </tr>
                <tr class="no-border" style="border-top:0.5px solid black; border-right: 0.5px solid black; border-left: 0.5px solid black;">
                    <td>Tax Amount in words: <b>{{ $includeTax ? ucwords(numberToWords($orderGst['total_gst'])) : "N/A" }}</b></td>
                </tr>
            </table>

            {{-- HSN Summary --}}
            <table>
                <thead>
                    <tr>
                        <th>HSN</th>
                        <th>Taxable Amount</th>
                        @if($isLocal === true)
                        <th>CGST Rate</th>
                        <th>CGST Amount</th>
                        <th>SGST Rate</th>
                        <th>SGST Amount</th>
                         @else
                        <th>IGST Rate</th>
                        <th>IGST Amount</th>
                        @endif
                        <th>Total Tax Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @if($includeTax && isset($hsnWiseSummary) && count($hsnWiseSummary) > 0)
                        @foreach($hsnWiseSummary as $hsn => $hsnData)
                        <tr>
                            <td>{{ $hsn }}</td>
                            <td class="right">{{ $hsnData['taxable_amount'] }}</td>
                            @if($isLocal === true)
                            <td class="center">{{ $hsnData['cgst_rate'] }}%</td>
                            <td class="right">{{ $hsnData['cgst'] }}</td>
                            <td class="center">{{ $hsnData['sgst_rate'] }}%</td>
                            <td class="right">{{ $hsnData['sgst'] }}</td>
                            @else
                            <td class="center">{{ $hsnData['igst_rate'] }}%</td>
                            <td class="right">{{ $hsnData['igst'] }}</td>
                            @endif
                            <td class="right">{{ $hsnData['total_gst'] }}</td>
                        </tr>
                        @endforeach
                    @else
                        @foreach($order->bkorderlineitems->unique('product_hsn') as $item)
                        <tr>
                            <td>{{ $item->product_hsn ?? '' }}</td>
                            <td class="right"></td>
                            @if($isLocal === true)
                            <td class="center"></td>
                            <td class="right"></td>
                            <td class="center"></td>
                            <td class="right"></td>
                            @else
                            <td class="center"></td>
                            <td class="right"></td>
                            @endif
                            <td class="right"></td>
                        </tr>
                        @endforeach
                    @endif
                </tbody>
            </table>

            {{-- Notes --}}
            <table class="no-border">
                <tr style="border-left: 0.5px solid black; border-right: 0.5px solid black; border-bottom: 0.5px solid black;">
                    <td><b>Notes:</b><br>{{ $order->notes ?? 'N/A' }}</td>
                </tr>
            </table>

            {{-- Terms / Bank Details / Signature --}}
            <table class="no-border" style="border-left: 1px solid black; border-right: 1px solid black; border-bottom: 1px solid black;">
                <tr>
                    <td style="width:40%; vertical-align: top; border-right: 0.5px solid black; padding:5px;">
                        <b>Terms and Conditions:</b><br>
                        {{ $order->terms_and_conditions ?? 'N/A' }}
                    </td>
                    @if($includeBankDetails && isset($bankDetails))
                    <td style="width:30%; vertical-align: top; border-right: 0.5px solid black; padding:5px;">
                        <b>Bank Details:</b><br>
                        Bank Name: {{ $bankDetails['bank_name'] ?? '' }}<br>
                        A/C No: {{ $bankDetails['account_no'] ?? '' }}<br>
                        IFSC Code: {{ $bankDetails['ifsc_code'] ?? '' }}<br>
                        @if(isset($bankDetails['branch']) && $bankDetails['branch'])
                        Branch: {{ $bankDetails['branch'] }}
                        @endif
                    </td>
                    @else
                    <td style="width:30%; vertical-align: top; padding:5px;">
                        &nbsp;
                    </td>
                    @endif
                    <td style="width:30%; text-align: right; vertical-align: bottom; padding:5px;">
                        for {{ $order->company->company_name ?? $order->company_name_billing }}<br><br><br>
                        <b>Authorised Signatory</b>
                    </td>
                </tr>
            </table>
        </div>
    @endif

    
    {{-- PAGE NUMBER --}}
        <div style="position: absolute; bottom: 5mm; right: 8mm; font-size: 10px; width: 100%; text-align: right;">
            Page {{ $page }} of {{ $pages }}
        </div>


@endfor

</body>
</html>
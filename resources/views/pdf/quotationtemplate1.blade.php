<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Quotation</title>
    <style>
        @page { size: A4; margin: 8mm; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            margin: 0;
            line-height: 1.5;
        }

        table { width: 100%; border-collapse: collapse; }
        th, td { border: 0.6px solid #000; padding: 4px; vertical-align: top; }
        th { background: #f6f6f6; font-weight: bold; }

        .no-border td, .no-border th { border: none; }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: bold; }

        .footer-section { page-break-inside: avoid;}
        .page-break { page-break-before: always; }

        .pagenum:before { content: counter(page); }
        
        /* Title styling */
        .invoice-title {
            text-align: center;
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 5px;
            page-break-after: avoid;
            page-break-inside: avoid;
        }
        
        /* Page container */
        .page-container {
            page-break-inside: avoid;
            min-height: 100vh;
        }
        
    </style>
</head>
<body>
@php
    $items = $quotation->quotationlineitems;
    $perPage = 12;
    $totalItems = count($items);
    $pages = ceil($totalItems / $perPage);
    
    // Check if we need an extra page for footer when last page has more than 3 items
    $lastPageItemCount = $totalItems % $perPage;
    if ($lastPageItemCount == 0) $lastPageItemCount = $perPage; // Full page
    
    $needsExtraPage = ($lastPageItemCount > 3);
    
    if ($needsExtraPage) {
        $pages += 1; // Add one more page for footer
    }
@endphp

{{-- LOOP THROUGH PAGES --}}
@for($page=1; $page <= $pages; $page++)
    
    {{-- Apply page break BEFORE the entire page content (except first page) --}}
    @if($page > 1)
        <div class="page-break"></div>
    @endif
    
    <div class="page-container">
        {{-- TAX INVOICE TITLE - ALWAYS AT TOP --}}
        <div class="invoice-title">Quotation</div>

        {{-- HEADER (repeated each page) --}}
        <div class="header">
            <table>
                <tr>
                    <td style="width:50%;">
                        <b>Company Details</b><br>
                        {{ $company['name'] }} <br>
                        {{ $company['address_line_1'] }}<br>
                        @if($company['address_line_2']) {{ $company['address_line_2'] }}<br> @endif
                        {{ $company['city'] }}, {{ $company['state'] }} - {{ $company['pincode'] }}<br>
                        GSTIN: {{ $company['gst_number'] ?? 'N/A' }}
                    </td>
                    <td style="width:25%;">
                        Quotation No: <b>{{ $quotation->quotation_no }}</b><br>
                        Financial Year: <b>{{ $quotation->financial_year }}</b>
                    </td>
                    <td style="width:25%;">
                        Date: <b>{{ \Carbon\Carbon::parse($quotation->quotation_date)->format('d-m-Y') }}</b><br>
                        Payment Terms: <b>{{ $quotation->payment_terms }}</b>
                    </td>
                </tr>
            </table>
            <table class="no-border">
                <tr>
                    <td style="width:50%;border-left:1px solid black;border-right:1px solid black;">
                        <b>Billing to</b><br>
                        {{ $quotation->company_name_billing }}<br>
                        {{ $quotation->company_address_billing }}<br>
                        {{ $quotation->company_city_billing }}, {{ $quotation->company_state_name_billing }} - {{ $quotation->company_pincode_billing }}<br>
                        GSTIN: {{ $quotation->company_GST_number_billing ?? 'N/A' }}
                    </td>
                    <td style="width:50%;border-right:1px solid black;">
                        Client: <b>{{ $quotation->client_name }}</b><br>
                        Email: <b>{{ $quotation->email }}</b><br>
                        Contact: <b>{{ $quotation->contact_number }}</b><br>
                        Total Amount: <b>{{ number_format($quotation_gst['total_with_gst'],2) }}</b>
                    </td>
                </tr>
            </table>
        </div>

        {{-- LISTING --}}
        @php
            // Calculate items for this page
            $start = ($page - 1) * $perPage;
            
            // If this is the extra page for footer, don't show any items
            if ($needsExtraPage && $page == $pages) {
                $pageItems = collect([]); // Empty collection
                $rowCount = 0;
            } else {
                $pageItems = $items->slice($start, $perPage);
                $rowCount = count($pageItems);
            }
            
            $shouldShowFooter = false;
            
            if ($needsExtraPage) {
                // If we need an extra page, only show footer on the extra (last) page
                $shouldShowFooter = ($page == $pages);
            } else {
                // Original logic: show footer on last page if 3 or fewer items
                $shouldShowFooter = ($page == $pages && $rowCount <= 3);
            }

            $hasLineItemDiscount = $items->where('line_item_discount', '>', 0)->count() > 0;
        @endphp

        <table>
            <thead>
                <tr>
                    <th class="center" style="width:5%;">Sl</th>
                    <th style="width:35%;">Description of Goods</th>
                    <th class="center" style="width:10%;">HSN</th>
                    <th class="center" style="width:10%;">Qty</th>
                    @if($includeTax)
                    <th class="center" style="width:10%;">GST %</th>
                    @endif
                    <th class="center" style="width:10%;">Rate</th>
                    <th class="center" style="width:5%;">Per</th>
                    @if($hasLineItemDiscount)
                    <th class="center" style="width:5%;">Dis %</th>
                    @endif
                    <th class="right" style="width:10%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($pageItems as $i => $item)
                    @php
                        // Serial number continues from previous pages
                        $serialNumber = $start + $i + 1;
                    @endphp
                    <tr>
                        <td class="center">{{ $serialNumber }}</td>
                        <td>
                            <b>{{ \Illuminate\Support\Str::limit($item->product_name, 25, '...') }}</b><br>
                            {{ \Illuminate\Support\Str::limit($item->product_description ?? 'No description', 25, '...') }}
                        </td>
                        <td class="center"> {{ \Illuminate\Support\Str::limit($item->product_hsn ?? 'N/A', 10, '') }}</td>
                        <td class="center">{{ $item->quantity }} {{ $item->unit }}</td>
                        @if($includeTax)
                        <td class="center">{{ $item->gst_rate }}%</td>
                        @endif
                        <td class="right">{{ number_format($item->rate,2) }}</td>
                        <td class="center">{{ $item->unit }}</td>
                        @if($hasLineItemDiscount)
                            <td class="center">{{ $item->line_item_discount ?? 0 }}%</td>
                        @endif
                        <td class="right">{{ number_format($item->amount_after_line_discount,2) }}</td>
                    </tr>
                @endforeach
                
                {{-- Fill empty rows only when showing footer and we have less than minimum rows --}}
                @if($shouldShowFooter && $rowCount > 0)
                @for ($j = $rowCount; $j < 3; $j++)
                <tr>
                    <td class="center">&nbsp;<br><br></td>
                    <td>&nbsp;<br><br></td>
                    <td class="center">&nbsp;<br><br></td>
                    <td class="center">&nbsp;<br><br></td>
                    @if($includeTax)
                    <td class="center">&nbsp;<br><br></td>
                    @endif
                    <td class="right">&nbsp;<br><br></td>
                    <td class="center">&nbsp;<br><br></td>
                    @if($hasLineItemDiscount)
                        <td class="center">&nbsp;<br><br></td>
                    @endif
                    <td class="right">&nbsp;<br><br></td>
                </tr>
                @endfor
            @elseif($shouldShowFooter && $rowCount == 0)
                {{-- If this is a blank footer page, add minimum rows for proper layout --}}
                @for ($j = 0; $j < 3; $j++)
                <tr>
                    <td class="center">&nbsp;<br><br></td>
                    <td>&nbsp;<br><br></td>
                    <td class="center">&nbsp;<br><br></td>
                    <td class="center">&nbsp;<br><br></td>
                    @if($includeTax)
                    <td class="center">&nbsp;<br><br></td>
                    @endif
                    <td class="right">&nbsp;<br><br></td>
                    <td class="center">&nbsp;<br><br></td>
                    @if($hasLineItemDiscount)
                        <td class="center">&nbsp;<br><br></td>
                    @endif
                    <td class="right">&nbsp;<br><br></td>
                </tr>
                @endfor
            @endif
            
            </tbody>
        </table>

        {{-- FOOTER ONLY WHEN CONDITIONS ARE MET --}}
        @if($shouldShowFooter)
            <div class="footer-section">
                {{-- Summary --}}
                <table>
                    @if($quotation->order_discount > 0)
                    <tr class="no-border"><td style="border-left: 1px solid black; border-right: 1px solid black;">Order Discount ({{ $quotation->order_discount }}%)</td><td class="right" style="border-right: 1px solid black;">{{ $quotation_gst['order_discount'] }}</td></tr>
                    @endif
                    <tr class="no-border"><td style="border-left: 1px solid black; border-right: 1px solid black;">Sub-Total</td><td class="right" style="border-right: 1px solid black;">{{ $quotation_gst['total_taxable_amount'] }}</td></tr>
                    @if($includeTax)
                        @if(isset($quotation_gst['total_cgst']))
                        <tr><td>C-GST</td><td class="right">{{ $quotation_gst['total_cgst'] }}</td></tr>
                        <tr><td>S-GST</td><td class="right">{{ $quotation_gst['total_sgst'] }}</td></tr>
                        @endif
                        @if(isset($quotation_gst['total_igst']))
                        <tr><td>I-GST</td><td class="right">{{ $quotation_gst['total_igst'] }}</td></tr>
                        @endif
                    @endif
                    <tr><td>Round-Off</td><td class="right">{{ $quotation_gst['round_off'] }}</td></tr>
                    <tr class="bold"><td>Grand Total</td><td class="right">{{ $quotation_gst['total_with_gst'] }}</td></tr>
                </table>

                {{-- Amount in words --}}
                @php
                    $totalAmount = (float) str_replace(',', '', $quotation_gst['total_with_gst']);
                    $taxAmount = $includeTax ? (float) str_replace(',', '', $quotation_gst['total_gst']) : 0;
                    
                    // You'll need to implement or include a number to words converter
                    $amount_in_words = ucwords(numberToWords($totalAmount));
                    $tax_amount_in_words = ucwords(numberToWords($taxAmount));
                @endphp
                <table>
                    <tr class="no-border" style="border-right: 1px solid black; border-left: 1px solid black;"><td>Amount in words: <b>{{ $amount_in_words }}</b></td></tr>
                    @if($includeTax)
                    <tr class="no-border" style="border-top:1px solid black; border-right: 1px solid black; border-left: 1px solid black;"><td>Tax Amount in words: <b>{{ $tax_amount_in_words }}</b></td></tr>
                    @endif
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
                        @foreach($hsnwisesummary as $hsnCode => $hsn)
                            <tr>
                                <td>{{ \Illuminate\Support\Str::limit($hsnCode ?? 'N/A', 10, '') }}</td>
                                <td class="right">{{ ($hsn['taxable_amount'] == 0 || $hsn['taxable_amount'] === '' || is_null($hsn['taxable_amount'])) ? '' : $hsn['taxable_amount'] }}</td>
                                 @if($isLocal === true)
                                    <td class="right">{{ ($hsn['cgst_rate'] == 0 || $hsn['cgst_rate'] === '' || is_null($hsn['cgst_rate'])) ? '' : $hsn['cgst_rate'] . '%' }}</td>
                                    <td class="right">{{ ($hsn['cgst'] == 0 || $hsn['cgst'] === '' || is_null($hsn['cgst'])) ? '' : $hsn['cgst'] }}</td>
                                    <td class="right">{{ ($hsn['sgst_rate'] == 0 || $hsn['sgst_rate'] === '' || is_null($hsn['sgst_rate'])) ? '' : $hsn['sgst_rate'] . '%' }}</td>
                                    <td class="right">{{ ($hsn['sgst'] == 0 || $hsn['sgst'] === '' || is_null($hsn['sgst'])) ? '' : $hsn['sgst'] }}</td>
                                @else
                                    <td class="right">{{ ($hsn['igst_rate'] == 0 || $hsn['igst_rate'] === '' || is_null($hsn['igst_rate'])) ? '' : $hsn['igst_rate'] . '%' }}</td>
                                    <td class="right">{{ ($hsn['igst'] == 0 || $hsn['igst'] === '' || is_null($hsn['igst'])) ? '' : $hsn['igst'] }}</td>
                                @endif
                                <td class="right">{{ ($hsn['total_gst'] == 0 || $hsn['total_gst'] === '' || is_null($hsn['total_gst'])) ? '' : $hsn['total_gst'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                {{-- Notes --}}
                <table class="no-border">
                    <tr style="border-left: 1px solid black; border-right: 1px solid black; border-bottom: 1px solid black"><td><b>Notes:</b><br>{{ $quotation->notes ?? 'N/A' }}</td></tr>
                </table>

               {{-- Terms / Bank Details / Signature --}}
<table class="no-border" style="border-left: 1px solid black; border-right: 1px solid black; border-bottom: 1px solid black; width:100%; border-collapse: collapse;">
    <tr>
        {{-- Terms and Conditions --}}
        <td style="width:40%; vertical-align: top; border-right: 1px solid black; padding:5px;">
            <b>Terms and Conditions:</b><br>
            {{ $quotation->terms_and_conditions ?? 'N/A' }}
        </td>

        {{-- Bank Details --}}
        @if($includeBankDetails)
        <td style="width:30%; vertical-align: top; border-right: 1px solid black; padding:5px;">
            <b>Bank Details:</b><br>
            Bank Name: {{ $bankDetails['bank_name'] ?? '' }} <br>
            A/C No: {{ $bankDetails['account_no'] ?? '' }} <br>
            IFSC Code: {{ $bankDetails['ifsc_code'] ?? '' }}<br>
            Branch: {{ $bankDetails['branch'] ?? '' }}
        </td>
        @else
        <td style="width:30%; vertical-align: top; padding:5px;">
            &nbsp;
        </td>
        @endif

        {{-- Signature --}}
        <td style="width:30%; text-align: right; vertical-align: bottom; padding:5px;">
            for {{ $company['name'] ?? $quotation->company_name_billing }} <br><br><br>
            <b>Authorised Signatory</b>
        </td>
    </tr>
</table>
            </div>
        @endif

        {{-- PAGE NUMBER --}}
        @if($pages > 1)
        <div style="position:absolute;bottom:5px;right:10px;font-size:10px;">
            Page {{ $page }} of {{ $pages }}
        </div>
        @endif
    </div>
@endfor

</body>
</html>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Quotation</title>
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
            display: flex;
            justify-content: space-between;
            align-items: center;
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
        .address-text.client { font-weight: normal; color: #000; }

        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .items-table th { background-color: #f1f5f9; padding: 8px; border-bottom: 1px solid #cbd5e1; font-weight: bold; font-size: 12px; color: #2563eb; text-align: center; }
        .items-table td { padding: 8px; border-bottom: 0.5px solid #e2e8f0; font-size: 12px;text-align: center; }

        .col-center { text-align: center; }
        .col-right { text-align: right; }

        .notes-section, .terms-section, .bank-details {
            margin-bottom: 15px;
        }

        .notes-title, .terms-title, .bank-title {
            font-weight: bold;
            font-size: 12px;
            margin-bottom: 5px;
        }

        .notes-text {
            font-size: 12px;
            line-height: 1.4;
        }

        .total-table { width: 200px; border: 1px solid #e0e0e0; margin-left: auto; }
        .total-row td { padding: 5px; border-bottom: 0.5px solid #e0e0e0; }
        .total-row-final td { padding: 8px; background-color: #f1f5f9; border-top: 1px solid #2563eb; }

        .grand-total-label { font-size: 14px; font-weight: bold; color: #2563eb; }
        .grand-total-value { font-size: 14px; text-align: right; font-weight: bold; color: #2563eb; }

        .signature { margin-top: 45px; text-align: center; }
        .signature-line { width: 150px; height: 1px; background-color: #000; margin: 0 auto 5px auto; }
        .signature-name { font-weight: bold; }

        .page-number { position: absolute; bottom: 20px; right: 20px; font-size: 12px; }
        .page-break { page-break-before: always; }
        .no-break { page-break-inside: avoid; }
        .blank-row td { height: 35px; border-bottom: 0.5px solid #e2e8f0; }
    </style>
</head>
<body>

@php
    $items = $quotation->quotationlineitems;
    $perPage = 12;
    $totalItems = count($items);
    $pages = ceil($totalItems / $perPage);

    $lastPageItemCount = $totalItems % $perPage;
    if ($lastPageItemCount == 0) $lastPageItemCount = $perPage;
    $needsExtraPage = ($lastPageItemCount > 3);
    if ($needsExtraPage) $pages += 1;
    
    // Define the missing variables
    $totalPages = $pages; // This is the fix
    $currentPage = 0; // This is for the page counter in the footer
    
    // Check for line item discount to conditionally show discount column
    $hasLineItemDiscount = $items->where('line_item_discount', '>', 0)->count() > 0;
@endphp

@for($page=1; $page <= $pages; $page++)
    @php
        $currentPage = $page; // Update currentPage variable for each page
    @endphp

    @if($page > 1)
        <div class="page-break"></div>
    @endif
    
    <div class="page-container no-break">
        {{-- HEADER --}}
        <div class="header-section">
            <div>
                <div class="invoice-title">QUOTATION</div>
            </div>
            <div class="company-info">
                <div class="company-name">{{ $company['name'] }}</div>
                <div class="address-text">{{ $company['address_line_1'] }}</div>
                @if($company['address_line_2'])
                    <div class="address-text">{{ $company['address_line_2'] }}</div>
                @endif
                <div class="address-text">{{ $company['city'] }}, {{ $company['state'] }} {{ $company['pincode'] }}</div>
                <div class="address-text">GST: {{ $company['gst_number'] ?? 'N/A' }}</div>
            </div>
        </div>

        {{-- INVOICE DETAILS --}}
        <table class="invoice-details">
            <tr>
                <td class="detail-label">Quotation No:</td>
                <td class="detail-label">Quotation Date:</td>
                <td class="detail-label">Financial Year:</td>
                <td class="detail-label">Payment Terms:</td>
            </tr>
            <tr>
                <td class="detail-value">{{ $quotation->quotation_no }}</td>
                <td class="detail-value">{{ \Carbon\Carbon::parse($quotation->quotation_date)->format('d/m/Y') }}</td>
                <td class="detail-value">{{ $quotation->financial_year }}</td>
                <td class="detail-value">{{ $quotation->payment_terms }}</td>
            </tr>
        </table>

        {{-- ADDRESSES --}}
        <table class="address-section" style="width:100%; border-collapse: collapse; margin-bottom:20px;">
            <tr>
                <td class="address-box" style="vertical-align: top; width:50%;">
                    <div class="address-title">Bill To:</div>
                    <div class="address-text">{{ $quotation->company_name_billing }}</div>
                    <div class="address-text">{{ $quotation->company_address_billing }}</div>
                    <div class="address-text">{{ $quotation->company_city_billing }}, {{ $quotation->company_state_name_billing }}</div>
                    <div class="address-text">PIN: {{ $quotation->company_pincode_billing }}</div>
                    <div class="address-text">GSTIN: {{ $quotation->company_GST_number_billing ?? 'N/A' }}</div>
                    @if($quotation->contact_number)
                        <div class="address-text">Phone: {{ $quotation->contact_number }}</div>
                    @endif
                </td>
    
                <td class="address-box" style="vertical-align: top; width:50%;">
                    <div class="address-title">Client Details:</div>
                    <div class="address-text">{{ $quotation->client_name }}</div>
                    <div class="address-text">Email: {{ $quotation->email }}</div>
                    <div class="address-text">Contact: {{ $quotation->contact_number }}</div>
                    <div class="address-text">Total Amount: {{ number_format($quotation_gst['total_with_gst'],2) }}</div>
                </td>
            </tr>
        </table>

        {{-- ITEMS TABLE --}}
        @php
            $start = ($page - 1) * $perPage;
            if ($needsExtraPage && $page == $pages) {
                $pageItems = collect([]);
                $rowCount = 0;
            } else {
                $pageItems = $items->slice($start, $perPage);
                $rowCount = count($pageItems);
            }
            $shouldShowFooter = $needsExtraPage ? ($page == $pages) : ($page == $pages && $rowCount <= 3);
        @endphp

        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 5%;">Sl</th>
                    <th style="width: 25%;">Description of Goods</th>
                    <th style="width: 10%;">HSN</th>
                    <th style="width: 8%;">Qty</th>
                    <th style="width: 8%;">Per</th>
                    <th style="width: 12%;">Rate</th>
                    @if($includeTax)
                        <th style="width: 8%;">GST %</th>
                    @endif
                    @if($hasLineItemDiscount)
                        <th style="width: 8%;">Dis %</th>
                    @endif 
                    <th style="width: 12%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($pageItems as $i => $item)
                    @php
                        // Serial number continues from previous pages
                        $serialNumber = $start + $i + 1;
                    @endphp
                    <tr>
                        <td class="col-center">{{ $serialNumber }}</td>
                        <td>
                            <div>{{ \Illuminate\Support\Str::limit($item->product_name, 25, '...') }}</div>
                            <span style="color:#666;">{{ \Illuminate\Support\Str::limit($item->product_description ?? '', 25, '...') }}</span>
                        </td>
                        <td class="col-center">{{ \Illuminate\Support\Str::limit($item->product_hsn ?? 'N/A', 10, '') }}</td>
                        <td class="col-center">{{ $item->quantity }}</td>
                        <td class="col-center">{{ $item->unit }}</td>
                        <td class="col-right">{{ number_format($item->rate,2) }}</td>
                        @if($includeTax)
                            <td class="col-center">{{ $item->gst_rate }}%</td>
                        @endif
                        @if($hasLineItemDiscount)
                            <td class="col-center">{{ $item->line_item_discount ?? 0 }}%</td>
                        @endif 
                        <td class="col-right">{{ number_format($item->amount_after_line_discount,2) }}</td>
                    </tr>
                @endforeach
                
                {{-- Fill empty rows only when showing footer and we have less than minimum rows --}}
                @if($shouldShowFooter && $rowCount > 0)
                    @for ($j = $rowCount; $j < 3; $j++)
                        <tr>
                            <td class="col-center">&nbsp;</td>
                            <td>&nbsp;</td>
                            <td class="col-center">&nbsp;</td>
                            <td class="col-center">&nbsp;</td>
                            <td class="col-center">&nbsp;</td>
                            <td class="col-right">&nbsp;</td>
                            @if($includeTax)
                                <td class="col-center">&nbsp;</td>
                            @endif
                            @if($hasLineItemDiscount)
                                <td class="col-center">&nbsp;</td>
                            @endif 
                            <td class="col-right">&nbsp;</td>
                        </tr>
                    @endfor
                @elseif($shouldShowFooter && $rowCount == 0)
                    {{-- If this is a blank footer page, add 3 rows for proper layout --}}
                    @for ($j = 0; $j < 3; $j++)
                        <tr class="blank-row">
                            <td class="col-center">&nbsp;</td>
                            <td>&nbsp;</td>
                            <td class="col-center">&nbsp;</td>
                            <td class="col-center">&nbsp;</td>
                            <td class="col-center">&nbsp;</td>
                            <td class="col-right">&nbsp;</td>
                            @if($includeTax)
                                <td class="col-center">&nbsp;</td>
                            @endif
                            @if($hasLineItemDiscount)
                                <td class="col-center">&nbsp;</td>
                            @endif 
                            <td class="col-right">&nbsp;</td>
                        </tr>
                    @endfor
                @endif
            </tbody>
        </table>

        {{-- FOOTER SECTION --}}
        @if($shouldShowFooter)
            <table class="no-break" style="width:100%; border-collapse: collapse; margin-top:20px;">
                <tr>
                    {{-- Left side (Notes & Terms) --}}
                    <td style="vertical-align: top; width:60%;">
                        @if($quotation->notes)
                            <div class="notes-section">
                                <div class="notes-title">Notes:</div>
                                <div class="notes-text">{{ $quotation->notes }}</div>
                            </div>
                        @endif

                        @if($quotation->terms_and_conditions)
                            <div class="terms-section">
                                <div class="terms-title">Terms & Conditions:</div>
                                <div class="notes-text">{{ $quotation->terms_and_conditions }}</div>
                            </div>
                        @endif
                    
                        @if($includeBankDetails)
                            <div class="bank-details">
                                <div class="bank-title">Bank Details:</div>
                                <div class="notes-text">Bank Name: {{ $bankDetails['bank_name'] ?? '' }} <br></div>
                                <div class="notes-text">A/C No: {{ $bankDetails['account_no'] ?? '' }} <br></div>
                                <div class="notes-text">IFSC Code: {{ $bankDetails['ifsc_code'] ?? '' }}<br></div>
                                <div class="notes-text">Branch: {{ $bankDetails['branch'] ?? '' }}</div>
                            </div>
                        @endif
                    </td>

                    {{-- Right side (Totals + Signature) --}}
                    <td style="vertical-align: top; text-align: right; width:40%;">
                        <table class="total-table" style="width:100%;">
                            @if($quotation->order_discount > 0)
                                <tr class="total-row">
                                    <td class="total-label">Order Discount ({{ $quotation->order_discount }}%):</td>
                                    <td class="total-value col-right">{{ $quotation_gst['order_discount'] }}</td>
                                </tr>
                            @endif
                            <tr class="total-row">
                                <td class="total-label">Sub-Total:</td>
                                <td class="total-value col-right">{{ $quotation_gst['total_taxable_amount'] }}</td>
                            </tr>
                            @if($includeTax)
                                @if(isset($quotation_gst['total_cgst']))
                                    <tr class="total-row">
                                        <td class="total-label">C-GST:</td>
                                        <td class="total-value col-right">{{ $quotation_gst['total_cgst'] }}</td>
                                    </tr>
                                    <tr class="total-row">
                                        <td class="total-label">S-GST:</td>
                                        <td class="total-value col-right">{{ $quotation_gst['total_sgst'] }}</td>
                                    </tr>
                                @endif
                                @if(isset($quotation_gst['total_igst']))
                                    <tr class="total-row">
                                        <td class="total-label">I-GST:</td>
                                        <td class="total-value col-right">{{ $quotation_gst['total_igst'] }}</td>
                                    </tr>
                                @endif
                            @endif
                            <tr class="total-row">
                                <td class="total-label">Round-Off:</td>
                                <td class="total-value col-right">{{ $quotation_gst['round_off'] }}</td>
                            </tr>
                            <tr class="total-row-final">
                                <td class="grand-total-label">Grand Total:</td>
                                <td class="grand-total-value col-right">{{ $quotation_gst['total_with_gst'] }}</td>
                            </tr>
                        </table>

                        {{-- Amount in words --}}
                        @php
                            $totalAmount = (float) str_replace(',', '', $quotation_gst['total_with_gst']);
                            $taxAmount = $includeTax ? (float) str_replace(',', '', $quotation_gst['total_gst']) : 0;
                            
                            $amount_in_words = ucwords(numberToWords($totalAmount));
                            $tax_amount_in_words = ucwords(numberToWords($taxAmount));
                        @endphp
                        
                        <div style="margin-top: 15px; font-size: 12px; text-align: left;">
                            <div><strong>Amount in words:</strong> {{ $amount_in_words }}</div>
                            @if($includeTax)
                                <div><strong>Tax Amount in words:</strong> {{ $tax_amount_in_words }}</div>
                            @endif
                        </div>

                        <div class="signature" style="margin-top:30px; text-align:center;">
                            <div style="text-align: center;">for {{ $company['name'] ?? $quotation->company_name_billing }}</div>
                            <div class="signature-line"></div>
                            <div class="signature-name">Authorised Signatory</div>
                        </div>
                    </td>
                </tr>
            </table>

            
        @endif

        {{-- PAGE NUMBER --}}
        @if($totalPages > 1)
            <div class="page-number">Page {{ $currentPage }} of {{ $totalPages }}</div>
        @endif
    </div>
@endfor

</body>
</html>
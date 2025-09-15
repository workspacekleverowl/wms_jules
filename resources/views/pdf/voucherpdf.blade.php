<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Voucher Document</title>
    <style>
        @page {
            size: A5;
            margin: 8mm;
        }
        body {
            margin: 0;
            padding: 5px;
            font-family: 'Arial Narrow', Arial, sans-serif;
            font-size: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
        }
        .page-break {
            page-break-before: always;
            break-before: page;
        }
        .avoid-break {
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .page-footer {
            position: absolute;
            bottom: -25px;
            width: 100%;
            text-align: right;
            font-size: 10px;
        }
        
        /* Force page breaks for different PDF generators */
        @media print {
            .page-break {
                page-break-before: always;
            }
            .avoid-break {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body style="margin: 0; padding: 10px; font-family: 'Arial Narrow', Arial, sans-serif; font-size: 12px;">
    @php
        $settings = [];
        foreach ($userSetting as $setting) {
            $settings[$setting->slug] = $setting->val === 'yes';
        }
        $itemsPerPage = 7; // Optimized for A5 size
        $voucherMetaCount = $voucher->Vouchermeta->count();
        $totalPages = ceil($voucherMetaCount / $itemsPerPage);
        
        // Check if we need an extra page for totals when last page has more than 3 items
        $lastPageItemCount = $voucherMetaCount - (($totalPages - 1) * $itemsPerPage);
        $needsExtraPage = ($lastPageItemCount > 3 && $lastPageItemCount <= 7);
        
        if ($needsExtraPage) {
            $totalPages += 1; // Add one more page for totals
        }
    @endphp

    @for ($page = 1; $page <= $totalPages; $page++)
        <div class="@if($page > 1)page-break @endif">
            <table>
                <!-- Page Header: Transaction Type -->
                <table style="font-size: 10px;width: 100%; border-collapse: collapse;border-spacing: 0; table-layout: fixed;">
                    <tr>
                        <td colspan="3" style="text-align: center; padding: 5px;">
                            <strong style="font-size: 14px;">  
                                @if(in_array($voucher->transaction_type, ['inward', 's_inward']))
                                    Material In
                                @else
                                    Material Out
                                @endif
                            </strong>
                        </td>
                    </tr>

                    <tr>
                        <td style="width: 50%; border-top: 1px solid black; border-left: 1px solid black; padding: 5px; vertical-align: top;">
                            @if(!empty($voucher->company->company_name))
                                <strong>{{ $voucher->company->company_name }}</strong><br>
                            @endif
                            @if(!empty($voucher->company->address1))
                                {{ $voucher->company->address1 }},<br>
                            @endif
                            @if(!empty($voucher->company->address2))
                                {{ $voucher->company->address2 }},<br>
                            @endif
                            @if(!empty($voucher->company->city) && !empty($voucher->company->pincode))
                                {{ $voucher->company->city }} - {{ $voucher->company->pincode }}<br>
                            @elseif(!empty($voucher->company->city))
                                {{ $voucher->company->city }}<br>
                            @elseif(!empty($voucher->company->pincode))
                                {{ $voucher->company->pincode }}<br>
                            @endif
                            @if(!empty($voucher->company->state->title))
                                {{ $voucher->company->state->title }}<br>
                            @endif
                            @if(!empty($voucher->company->gst_number))
                                GSTIN: {{ $voucher->company->gst_number }}<br>
                            @endif
                            @if(!empty($voucher->company->state->title))
                                State Name: {{ $voucher->company->state->title }}
                            @endif
                        </td>
                        <td style="width: 25%; border: 1px solid black; vertical-align: top; padding: 0;">
                            <table style="width: 100%; border-collapse: collapse;">
                                <tr>
                                    <td style="padding: 5px;">Challan No.</td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px; border-bottom: 1px solid black;font-weight: bold;">
                                        {{ !empty($voucher->voucher_no) ? $voucher->voucher_no : '-' }}
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px;">Vehicle No.</td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px;font-weight: bold;">
                                        {{ !empty($voucher->vehicle_number) ? $voucher->vehicle_number : '-' }}
                                    </td>
                                </tr>
                            </table>
                        </td>
                        <td style="width: 25%; border: 1px solid black; vertical-align: top; padding: 0;">
                            <table style="width: 100%; border-collapse: collapse;">
                                <tr>
                                    <td style="padding: 5px;">Dated</td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px; border-bottom: 1px solid black;font-weight: bold;">
                                        {{ !empty($voucher->transaction_date) ? date('d-M-y', strtotime($voucher->transaction_date)) : '-' }}
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px;">Date & Time of Issue</td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px;font-weight: bold;">
                                        {{ !empty($voucher->issue_date) ? date('d-M-Y \a\t H:i', strtotime($voucher->issue_date)) : '-' }}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="width: 50%; border-top: 1px solid black; border-left: 1px solid black; padding: 5px; vertical-align: top;">
                            Party<br>
                            @if(!empty($voucher->party->name))
                                <strong>{{ $voucher->party->name }}</strong><br>
                            @endif
                            @if(!empty($voucher->party->address1))
                                {{ $voucher->party->address1 }},<br>
                            @endif
                            @if(!empty($voucher->party->address2))
                                {{ $voucher->party->address2 }},<br>
                            @endif
                            @if(!empty($voucher->party->city) && !empty($voucher->party->pincode))
                                {{ $voucher->party->city }} - {{ $voucher->party->pincode }}<br>
                            @elseif(!empty($voucher->party->city))
                                {{ $voucher->party->city }}<br>
                            @elseif(!empty($voucher->party->pincode))
                                {{ $voucher->party->pincode }}<br>
                            @endif
                            @if(!empty($voucher->party->gst_number))
                                GSTIN/UIN: {{ $voucher->party->gst_number }}<br>
                            @endif
                            @if(!empty($voucher->party->state->title))
                                State Name: {{ $voucher->party->state->title }}
                            @endif
                        </td>
                        <td colspan="2" style="border-top: 1px solid black; border-left: 1px solid black; border-right: 1px solid black; vertical-align: top; padding: 0;">
                            <table style="border-collapse: collapse;">
                                <tr>
                                    <td style="padding: 5px;">Description</td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px;">{{ !empty($voucher->description) ? $voucher->description : '-' }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                <!-- Product Details Table -->
                <tr>
                    <td colspan="3" style="border-collapse: collapse;padding: 0; margin: 0;">
                        <table style="width: 100%;border: 1px solid black; border-collapse: collapse; border-spacing: 0; table-layout: fixed;padding: 0; margin: 0;">
                            <tr>
                                <th style="border-left: 1px solid black; border-right: 1px solid black; border-bottom: 1px solid black; padding: 5px; width: 5%;">Sl</th>
                                <th style="border-right: 1px solid black; border-bottom: 1px solid black; padding: 5px; width: 20%;">Description of Goods</th>
                                <th style="border-right: 1px solid black; border-bottom: 1px solid black; padding: 5px; width: 10%;">HSN</th>
                                <th style="border-right: 1px solid black; border-bottom: 1px solid black; padding: 5px; width: 15%;">Quantity</th>
                                <th style="border-right: 1px solid black; border-bottom: 1px solid black; padding: 5px; width: 10%;">Rate</th>
                                <th style="border-right: 1px solid black; border-bottom: 1px solid black; padding: 5px; width: 5%;">Per</th>
                                <th style="border-right: 1px solid black; border-bottom: 1px solid black; padding: 5px; width: 15%;">Amount</th>
                            </tr>
                            
                            @php
                                // If this is the extra page for totals, don't show any items
                                if ($needsExtraPage && $page == $totalPages) {
                                    $startIndex = $voucherMetaCount; // Start beyond available items
                                    $endIndex = $voucherMetaCount;   // End at same point
                                    $rowCount = 0;
                                } else {
                                    $startIndex = ($page - 1) * $itemsPerPage;
                                    $endIndex = min($startIndex + $itemsPerPage, $voucherMetaCount);
                                    $rowCount = $endIndex - $startIndex;
                                }
                            @endphp

                            @for ($i = $startIndex; $i < $endIndex; $i++)
                            @php
                                $item = $voucher->Vouchermeta[$i];
                                $amount = $item->item_quantity * $item->material_price;
                            @endphp
                            <tr>
                                <td style="border-left: 1px solid black; border-right: 1px solid black; text-align: center;">{{ $i + 1 }}</td>
                                <td style="border-left: 1px solid black; border-right: 1px solid black; padding: 5px;">
                                    @if(!empty($item->Item->name))
                                        <strong style="font-size: 10px;">{{ $item->Item->name }}</strong><br>
                                    @endif
                                    <span style="font-size: 8px; padding-left: 10px; display: block;">
                                        @if(!empty($item->Item->item_code))
                                            Item Code: {{ $item->Item->item_code }}<br>
                                        @endif
                                        @if(($settings['voucher_include_item_wt'] ?? true) && !empty($item->Item->raw_weight))
                                            Casting Wt: {{ $item->Item->raw_weight }}<br>
                                            Total Casting Wt: {{ $item->Item->raw_weight * $item->item_quantity }}
                                        @endif
                                    </span>
                                </td>
                                <td style="border-left: 1px solid black; border-right: 1px solid black; text-align: center;">
                                    {{ ($settings['voucher_include_hsn_sac'] ?? true) && !empty($item->Item->hsn) ? $item->Item->hsn : '-' }}
                                </td>
                                <td style="border-left: 1px solid black; border-right: 1px solid black; text-align: center;">
                                    {{ !empty($item->item_quantity) ? $item->item_quantity . ' Nos' : '- Nos' }}
                                </td>
                                <td style="border-left: 1px solid black; border-right: 1px solid black; text-align: center;">
                                    {{ ($settings['voucher_include_rate'] ?? true) && !empty($item->material_price) ? number_format($item->material_price, 2) : '-' }}
                                </td>
                                <td style="border-left: 1px solid black; border-right: 1px solid black; text-align: center;">
                                    {{ ($settings['voucher_include_rate'] ?? true) ? 'Nos' : '-' }}
                                </td>
                                <td style="border-left: 1px solid black; border-right: 1px solid black; text-align: right;">
                                    {{ ($settings['voucher_include_rate'] ?? true) ? number_format($amount, 2) : '-' }}
                                </td>
                            </tr>
                            @endfor
                            
                            {{-- Fill empty rows to make minimum 3 rows --}}
                            @for ($j = $rowCount; $j < 3; $j++)
                            <tr>
                                <td style="border-left: 1px solid black; border-right: 1px solid black; text-align: center;">&nbsp;<br><br></td>
                                <td style="border-left: 1px solid black; border-right: 1px solid black; padding: 5px;">&nbsp;&nbsp;<br><br></td>
                                <td style="border-left: 1px solid black; border-right: 1px solid black; text-align: center;">&nbsp;<br><br></td>
                                <td style="border-left: 1px solid black; border-right: 1px solid black; text-align: center;">&nbsp;<br><br></td>
                                <td style="border-left: 1px solid black; border-right: 1px solid black; text-align: center;">&nbsp;<br><br></td>
                                <td style="border-left: 1px solid black; border-right: 1px solid black; text-align: center;">&nbsp;<br><br></td>
                                <td style="border-left: 1px solid black; border-right: 1px solid black; text-align: right;">&nbsp;<br><br></td>
                            </tr>
                            @endfor
                        
                            <!-- Totals and Footer Logic -->
                            @php
                                $shouldShowTotals = false;
                                
                                if ($needsExtraPage) {
                                    // If we need an extra page, only show totals on the extra (last) page
                                    $shouldShowTotals = ($page == $totalPages);
                                } else {
                                    // Original logic: show totals on last page if 3 or fewer items
                                    $shouldShowTotals = ($page == $totalPages && $rowCount <= 3);
                                }
                            @endphp
                            
                            @if ($shouldShowTotals)
                                <tbody style="page-break-inside: avoid;">
                                    <tr>
                                        <td colspan="6" style="border: 1px solid black; text-align: right; padding: 5px;">
                                            Total<br>
                                            @if($settings['voucher_include_gst'] ?? true)
                                                @php
                                                    $is_same_state = $voucher->company->state->title === $voucher->party->state->title;
                                                @endphp
                                                @if($is_same_state)
                                                    C-GST<br>
                                                    S-GST<br>
                                                @else
                                                    I-GST<br>
                                                @endif
                                            @endif
                                        </td>
                                        <td style="border: 1px solid black; text-align: right; padding: 5px;">
                                            {{ ($settings['voucher_include_rate'] ?? true) ? number_format($voucher->Vouchermeta->sum(fn($item) => $item->item_quantity * $item->material_price), 2) : '-' }}<br>
                                            @if($settings['voucher_include_gst'] ?? true)
                                                @if($is_same_state)
                                                    {{ ($settings['voucher_include_rate'] ?? true) ? number_format($voucher->Vouchermeta->sum(fn($item) => ($item->item_quantity * $item->material_price * $item->gst_percent_rate) / 200), 2) : '-' }}<br>
                                                    {{ ($settings['voucher_include_rate'] ?? true) ? number_format($voucher->Vouchermeta->sum(fn($item) => ($item->item_quantity * $item->material_price * $item->gst_percent_rate) / 200), 2) : '-' }}<br>
                                                @else
                                                    {{ ($settings['voucher_include_rate'] ?? true) ? number_format($voucher->Vouchermeta->sum(fn($item) => ($item->item_quantity * $item->material_price * $item->gst_percent_rate) / 100), 2) : '-' }}<br>
                                                @endif
                                            @endif
                                        </td>
                                    </tr>
                            
                                    <tr>
                                        <td colspan="3" style="border: 1px solid black; text-align: center; padding: 5px;">Total</td>
                                        <td style="border: 1px solid black; text-align: center; padding: 5px;">
                                            {{ $voucher->Vouchermeta->sum('item_quantity') }} Nos
                                        </td>
                                        <td colspan="3" style="border: 1px solid black; text-align: right; padding: 5px;">
                                            @if($settings['voucher_include_rate'] ?? true)
                                                {{ number_format($voucher->Vouchermeta->sum(function($item) use ($settings) {
                                                    $amount = $item->item_quantity * $item->material_price;
                                                    $gst = 0;
                                                    if ($settings['voucher_include_gst'] ?? true) {
                                                        $gst = ($amount * $item->gst_percent_rate) / 100;
                                                    }
                                                    return $amount + $gst;
                                                }), 2) }}
                                            @else
                                                -
                                            @endif
                                        </td>
                                    </tr>
                            
                                    <tr>
                                        <td colspan="7" style=" padding: 5px;">
                                            <strong>Amount Chargeable (in words)</strong><br>
                                            @if($settings['voucher_include_rate'] ?? true)
                                                @php
                                                    $total = $voucher->Vouchermeta->sum(function($item) use ($settings) {
                                                        $amount = $item->item_quantity * $item->material_price;
                                                        $gst = 0;
                                                        if ($settings['voucher_include_gst'] ?? true) {
                                                            $gst = ($amount * $item->gst_percent_rate) / 100;
                                                        }
                                                        return $amount + $gst;
                                                    });
                                                @endphp
                                                {{ ucwords(numberToWords($total)) }}
                                            @else
                                                -
                                            @endif
                                        </td>
                                    </tr>
                                </tbody>
                            @endif
                        </table>
                    </td>
                </tr>
                @if ($shouldShowTotals)
                    <!-- HSN/SAC Tax Table -->
                    <tr style="page-break-inside: avoid;">
                        <td colspan="3" style="padding: 0; margin: 0;">
                            <table style="width: 100%; border-collapse: collapse; border-spacing: 0; table-layout: fixed;">
                                <tr>
                                    <th style="border-left: 1px solid black; padding: 5px; text-align: center; width: 20%;">HSN/SAC</th>
                                    <th style="border-left: 1px solid black; padding: 5px; text-align: center; width: 20%;">Taxable Value</th>
                                    <th style="border-left: 1px solid black; padding: 0; text-align: center; width: 20%;">
                                        <table style="width: 100%; border-collapse: collapse; border-spacing: 0; table-layout: fixed;">
                                            <tr>
                                                <th colspan="2" style="border-bottom: 1px solid black; text-align: center; padding: 2px;">CGST</th>
                                            </tr>
                                            <tr>
                                                <th style="border-right: 1px solid black; text-align: center; width: 50%; padding: 2px;">Rate</th>
                                                <th style="text-align: center; width: 50%; padding: 2px;">Amount</th>
                                            </tr>
                                        </table>
                                    </th>
                                    <th style="border-left: 1px solid black; padding: 0; text-align: center; width: 20%;">
                                        <table style="width: 100%; border-collapse: collapse; border-spacing: 0; table-layout: fixed;">
                                            <tr>
                                                <th colspan="2" style="border-bottom: 1px solid black; text-align: center; padding: 2px;">SGST/IGST</th>
                                            </tr>
                                            <tr>
                                                <th style="border-right: 1px solid black; text-align: center; width: 50%; padding: 2px;">Rate</th>
                                                <th style="text-align: center; width: 50%; padding: 2px;">Amount</th>
                                            </tr>
                                        </table>
                                    </th>
                                    <th style="border-left: 1px solid black;border-right: 1px solid black; padding: 5px; text-align: center; width: 20%;">Total Tax Amount</th>
                                </tr>

                                @php
                                    $is_same_state = $voucher->company->state->title === $voucher->party->state->title;
                                    $taxable_total = 0;
                                    $cgst_total = 0;
                                    $sgst_total = 0;
                                    $igst_total = 0;
                                    $total_tax_amount = 0;
                                    $allowTaxView = ($settings['voucher_include_hsn_sac'] ?? true)
                                                && ($settings['voucher_include_rate'] ?? true)
                                                && ($settings['voucher_include_gst'] ?? true);
                                @endphp

                                @foreach($voucher->Vouchermeta->groupBy('product.hsn') as $hsn => $items)
                                    @php
                                        $taxable_value = $items->sum(fn($item) => $item->item_quantity * $item->material_price);
                                        $gst_rate = $items->first()->gst_percent_rate;

                                        if ($is_same_state) {
                                            $cgst_rate = $gst_rate / 2;
                                            $sgst_rate = $gst_rate / 2;
                                            $cgst_amount = $taxable_value * ($cgst_rate / 100);
                                            $sgst_amount = $taxable_value * ($sgst_rate / 100);
                                            $igst_amount = 0;
                                        } else {
                                            $cgst_rate = 0;
                                            $sgst_rate = $gst_rate;
                                            $cgst_amount = 0;
                                            $sgst_amount = $taxable_value * ($sgst_rate / 100);
                                            $igst_amount = $sgst_amount;
                                        }

                                        $total_tax = $cgst_amount + $sgst_amount;

                                        $taxable_total += $taxable_value;
                                        $cgst_total += $cgst_amount;
                                        $sgst_total += $sgst_amount;
                                        $total_tax_amount += $total_tax;
                                    @endphp

                                    <tr>
                                        <td style="border: 1px solid black; padding: 5px; text-align: center;">
                                            {{ $allowTaxView && !empty($hsn) ? $hsn : null }}
                                        </td>
                                        <td style="border: 1px solid black; padding: 5px; text-align: center;">
                                            {{ $allowTaxView ? number_format($taxable_value, 2) : null }}
                                        </td>
                                        <td style="border: 1px solid black; padding: 0; text-align: center;">
                                            <table style="width: 100%; border-collapse: collapse; border-spacing: 0; table-layout: fixed;">
                                                <tr>
                                                    <td style="border-right: 1px solid black; text-align: center; width: 50%; padding: 2px;">
                                                        {{ $allowTaxView && $cgst_rate ? $cgst_rate . '%' : null }}
                                                    </td>
                                                    <td style="text-align: center; width: 50%; padding: 2px;">
                                                        {{ $allowTaxView ? number_format($cgst_amount, 2) : null }}
                                                    </td>
                                                </tr>
                                            </table>
                                        </td>
                                        <td style="border: 1px solid black; padding: 0; text-align: center;">
                                            <table style="width: 100%; border-collapse: collapse; border-spacing: 0; table-layout: fixed;">
                                                <tr>
                                                    <td style="border-right: 1px solid black; text-align: center; width: 50%; padding: 2px;">
                                                        {{ $allowTaxView && $sgst_rate ? $sgst_rate . '%' : null }}
                                                    </td>
                                                    <td style="text-align: center; width: 50%; padding: 2px;">
                                                        {{ $allowTaxView ? number_format($sgst_amount, 2) : null }}
                                                    </td>
                                                </tr>
                                            </table>
                                        </td>
                                        <td style="border: 1px solid black; padding: 5px; text-align: center;">
                                            {{ $allowTaxView ? number_format($total_tax, 2) : null }}
                                        </td>
                                    </tr>
                                @endforeach

                                <!-- Totals -->
                                <tr>
                                    <td style="border: 1px solid black; padding: 5px; text-align: center; font-weight: bold;">Total</td>
                                    <td style="border: 1px solid black; padding: 5px; text-align: center; font-weight: bold;">
                                        {{ $allowTaxView ? number_format($taxable_total, 2) : null }}
                                    </td>
                                    <td style="border: 1px solid black; padding: 0; text-align: center; font-weight: bold;">
                                        <table style="width: 100%; border-collapse: collapse; border-spacing: 0; table-layout: fixed;">
                                            <tr>
                                                <td style="border-right: 1px solid black; text-align: center; width: 50%; padding: 2px;">-</td>
                                                <td style="text-align: center; width: 50%; padding: 2px;">
                                                    {{ $allowTaxView ? number_format($cgst_total, 2) : null }}
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                    <td style="border: 1px solid black; padding: 0; text-align: center; font-weight: bold;">
                                        <table style="width: 100%; border-collapse: collapse; border-spacing: 0; table-layout: fixed;">
                                            <tr>
                                                <td style="border-right: 1px solid black; text-align: center; width: 50%; padding: 2px;">-</td>
                                                <td style="text-align: center; width: 50%; padding: 2px;">
                                                    {{ $allowTaxView ? number_format($sgst_total, 2) : null }}
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                    <td style="border: 1px solid black; padding: 5px; text-align: center; font-weight: bold;">
                                        {{ $allowTaxView ? number_format($total_tax_amount, 2) : null }}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Tax Amount in words -->
                    <tr style="page-break-inside: avoid;">
                        <td colspan="3" style="border-left: 1px solid black; border-right: 1px solid black; border-bottom: 1px solid black; padding: 5px;">
                            <strong>Tax Amount (in words):</strong>
                            {{ $allowTaxView ? ucwords(numberToWords($voucher->Vouchermeta->sum(function($item) {
                                $amount = $item->item_quantity * $item->material_price;
                                return ($amount * $item->gst_percent_rate) / 100;
                            }))) : null }}
                        </td>
                    </tr>
               

                
                <!-- Signature section -->
                <tr>
                    <td colspan="3" style="border: 1px solid black;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <tr>
                                <td style="width: 60%; padding: 10px; vertical-align: top;">
                                    <br><br>
                                </td>
                                <td style="width: 40%; text-align: right; padding: 10px;">
                                    @if(!empty($voucher->company->company_name))
                                        for {{ $voucher->company->company_name }}<br><br><br>
                                    @endif
                                    Authorised Signatory
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                @endif
            </table>
        </div>
    @endfor  
</body>
</html>
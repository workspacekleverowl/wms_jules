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
            page-break-after: always;
            position: relative;
        }
        .page-footer {
            position: absolute;
            bottom: -25px;
            width: 100%;
            text-align: right;
            font-size: 10px;
        }
    </style>
    
</head>
<body style="margin: 0; padding: 10px; font-family: 'Arial Narrow', Arial, sans-serif; font-size: 12px;">
    @php
        $itemsPerPage = 3; // Optimized for A5 size
        $voucherMetaCount = $voucher->Vouchermeta->count();
        $totalPages = ceil($voucherMetaCount / $itemsPerPage);
    @endphp

    @for ($page = 1; $page <= $totalPages; $page++)
        <div class="@if($page > 1)page-break @endif">
            <table>
                <!-- Page Header: Transaction Type -->
                <table style="font-size: 10px;width: 100%; border-collapse: collapse; border: 1px solid black;border-spacing: 0; table-layout: fixed;">
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
                        <td style="width: 50%; border: 1px solid black; padding: 5px; vertical-align: top;">
                            <strong>{{ $voucher->company->company_name }}</strong><br>
                            {{ $voucher->company->address1 }},<br>
                            {{ $voucher->company->address2 }},<br>
                            {{ $voucher->company->city }} - {{ $voucher->company->pincode }}<br>
                            {{ $voucher->company->state->title }}<br>
                            GSTIN: {{ $voucher->company->gst_number }}<br>
                            State Name: {{ $voucher->company->state->title }}
                        </td>
                        <td style="width: 25%; border: 1px solid black; vertical-align: top; padding: 0;">
                            <table style="width: 100%; border-collapse: collapse;">
                                <tr>
                                    <td style="padding: 5px;">Challan No.</td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px; border-bottom: 1px solid black;font-weight: bold;">{{ $voucher->voucher_no }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px;">Vehicle No.</td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px;font-weight: bold;">{{ $voucher->vehicle_number }}</td>
                                </tr>
                            </table>
                        </td>
                        <td style="width: 25%; border: 1px solid black; vertical-align: top; padding: 0;">
                            <table style="width: 100%; border-collapse: collapse;">
                                <tr>
                                    <td style="padding: 5px;">Dated</td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px; border-bottom: 1px solid black;font-weight: bold;">{{ date('d-M-y', strtotime($voucher->transaction_date)) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px;">Date & Time of Issue</td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px;font-weight: bold;"> {{ date('d-M-Y \a\t H:i', strtotime($voucher->issue_date)) }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="width: 50%; border: 1px solid black; padding: 5px; vertical-align: top;">
                            Buyer (Bill to)<br>
                            <strong>{{ $voucher->party->name }}</strong><br>
                            {{ $voucher->party->address1 }},<br>
                            {{ $voucher->party->address2 }},<br>
                            {{ $voucher->party->city }} - {{ $voucher->party->pincode }}<br>
                            GSTIN/UIN: {{ $voucher->party->gst_number }}<br>
                            State Name: {{ $voucher->party->state->title }}
                        </td>
                        <td colspan="2" style="border: 1px solid black; vertical-align: top; padding: 0;">
                            <table style="border-collapse: collapse;">
                                <tr>
                                    <td style="padding: 5px;">Description</td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px;">{{ $voucher->description }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                <!-- Product Details Table -->
                <tr>
                    <td colspan="3" style="border-collapse: collapse;padding: 0; margin: 0;">
                        <table style="width: 100%; border-collapse: collapse; border-spacing: 0; table-layout: fixed;padding: 0; margin: 0;">
                            <tr>
                                <th style="border: 1px solid black; padding: 5px; width: 5%;">Sl</th>
                                <th style="border: 1px solid black; padding: 5px; width: 20%;">Description of Goods</th>
                                <th style="border: 1px solid black; padding: 5px; width: 10%;">HSN</th>
                                <th style="border: 1px solid black; padding: 5px; width: 15%;">Quantity</th>
                                <th style="border: 1px solid black; padding: 5px; width: 10%;">Rate</th>
                                <th style="border: 1px solid black; padding: 5px; width: 5%;">Per</th>
                                <th style="border: 1px solid black; padding: 5px; width: 15%;">Amount</th>
                            </tr>
                            
                            @php
                                $startIndex = ($page - 1) * $itemsPerPage;
                                $endIndex = min($startIndex + $itemsPerPage, $voucherMetaCount);
                            @endphp

                            @for ($i = $startIndex; $i < $endIndex; $i++)
                                @php
                                    $item = $voucher->Vouchermeta[$i];
                                    $amount = $item->item_quantity * $item->material_price;
                                    $gst_amount = ($amount * $item->gst_percent_rate) / 100;
                                    $total_amount = $amount + $gst_amount;
                                @endphp
                                <tr>
                                    <td style="border: 1px solid black; text-align: center;">{{ $i + 1 }}</td>
                                    <td style="border: 1px solid black; padding: 5px;width: 30%;">
                                        <strong style="font-size: 10px;">{{ $item->Item->name }}</strong><br>
                                        <span style="font-size: 8px; padding-left: 10px; display: block;">
                                            Item Code: {{ $item->Item->item_code }}<br>
                                            Casting Wt: {{ $item->Item->raw_weight }}<br>
                                            Total Casting Wt: {{ $item->Item->raw_weight * $item->item_quantity }}
                                        </span>
                                    </td>
                                    <td style="border: 1px solid black; padding: 5px; text-align: center;">{{ $item->Item->hsn }}</td>
                                    <td style="border: 1px solid black; padding: 5px; text-align: center;">{{ $item->item_quantity }} Nos</td>
                                    <td style="border: 1px solid black; padding: 5px; text-align: center;">{{ number_format($item->material_price , 2) }}</td>
                                    <td style="border: 1px solid black; padding: 5px; text-align: center;">Nos</td>
                                    <td style="border: 1px solid black; padding: 5px; text-align: right;">{{ number_format($amount, 2) }}</td>
                    
                                </tr>
                            @endfor

                           

            <!-- Totals on Last Page -->
            @if ($page == $totalPages)
                                <tr>
                                    <td colspan="6" style="border: 1px solid black; text-align: right; padding: 5px;">
                                        Total<br>
                                        @php
                                            $is_same_state = $voucher->company->state->title === $voucher->party->state->title;
                                        @endphp
                                        @if($is_same_state)
                                            C-GST<br>
                                            S-GST<br>
                                        @else
                                            I-GST<br>
                                        @endif
                                    </td>
                                    <td style="border: 1px solid black; text-align: right; padding: 5px;">
                                        {{ number_format($voucher->Vouchermeta->sum(function($item) { 
                                            return $item->item_quantity * ($item->material_price);
                                        }), 2) }}<br>
                                        @if($is_same_state)
                                            {{ number_format($voucher->Vouchermeta->sum(function($item) {
                                                $amount = $item->item_quantity * ($item->material_price);
                                                return ($amount * $item->gst_percent_rate) / 200;
                                            }), 2) }}<br>
                                            {{ number_format($voucher->Vouchermeta->sum(function($item) {
                                                $amount = $item->item_quantity * ($item->material_price);
                                                return ($amount * $item->gst_percent_rate) / 200;
                                            }), 2) }}<br>
                                        @else
                                        {{ number_format($voucher->Vouchermeta->sum(function($item) {
                                            $amount = $item->item_quantity * ($item->material_price);
                                            return ($amount * $item->gst_percent_rate) / 100;
                                        }), 2) }}<br>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="3" style="border: 1px solid black; text-align: center; padding: 5px;">Total</td>
                                    <td style="border: 1px solid black; text-align: center; padding: 5px;">{{ $voucher->Vouchermeta->sum('item_quantity') }} Nos</td>
                                    <td colspan="3" style="border: 1px solid black; text-align: right; padding: 5px;">{{ number_format($voucher->Vouchermeta->sum(function($item) {
                                        $amount = $item->item_quantity * ($item->material_price);
                                        $gst = ($amount * $item->gst_percent_rate) / 100;
                                        return $amount + $gst;
                                        }), 2) }}
                                    </td>
                                </tr>
                                           
                        </table>
                    </td>
                </tr>

                <tr>
                    <td colspan="3" style="border: 1px solid black; padding: 5px;">
                    <strong>Amount Chargeable (in words)</strong><br>
                        @php
                        $total = $voucher->Vouchermeta->sum(function($item) {
                            $amount = $item->item_quantity * ($item->material_price);
                            $gst = ($amount * $item->gst_percent_rate) / 100;
                            return $amount + $gst;
                        });
                        @endphp
                        {{ ucwords(numberToWords($total)) }}
                    </td>
                </tr>
            @endif                
                          

                               

            <!-- Footer on Last Page -->
            @if ($page == $totalPages)
                <tr>
                    <td colspan="3" style="padding: 0; margin: 0;">
                        <table style="width: 100%; border-collapse: collapse; border-spacing: 0; table-layout: fixed;">
                            <tr>
                                <th style="border: 1px solid black; padding: 0; text-align: center; width: 20%;">HSN/SAC</th>
                                <th style="border: 1px solid black; padding: 0; text-align: center; width: 20%;">Taxable Value</th>
                                <th style="border: 1px solid black; padding: 0; text-align: center; width: 20%;">
                                    <table style="width: 100%; border-collapse: collapse; border-spacing: 0; table-layout: fixed;">
                                        <tr>
                                            <th colspan="2" style="border-bottom: 1px solid black; text-align: center;">CGST</th>
                                        </tr>
                                        <tr>
                                            <th style="border-right: 1px solid black; text-align: center; width: 50%; padding: 0;">Rate</th>
                                            <th style="text-align: center; width: 50%; padding: 0;">Amount</th>
                                        </tr>
                                    </table>
                                </th>
                                <th style="border: 1px solid black; padding: 0; text-align: center; width: 20%;">
                                    <table style="width: 100%; border-collapse: collapse; border-spacing: 0; table-layout: fixed;">
                                        <tr>
                                            <th colspan="2" style="border-bottom: 1px solid black; text-align: center;">SGST/IGST</th>
                                        </tr>
                                        <tr>
                                            <th style="border-right: 1px solid black; text-align: center; width: 50%; padding: 0;">Rate</th>
                                            <th style="text-align: center; width: 50%; padding: 0;">Amount</th>
                                        </tr>
                                    </table>
                                </th>
                                <th style="border: 1px solid black; padding: 0; text-align: center; width: 20%;">Total Tax Amount</th>
                            </tr>
                            @php
                                $is_same_state = $voucher->company->state->title === $voucher->party->state->title;
                                $taxable_total = 0;
                                $cgst_total = 0;
                                $sgst_total = 0;
                                $igst_total = 0;
                                $total_tax_amount = 0;
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
                                <td style="border: 1px solid black; padding: 0; text-align: center;">{{ $hsn }}</td>
                                <td style="border: 1px solid black; padding: 0; text-align: center;">{{ number_format($taxable_value, 2) }}</td>
                                <td style="border: 1px solid black; padding: 0; text-align: center;">
                                    <table style="width: 100%; border-collapse: collapse; border-spacing: 0; table-layout: fixed;">
                                        <tr>
                                            <td style="border-right: 1px solid black; text-align: center; width: 50%; padding: 0;">{{ $cgst_rate ? $cgst_rate . '%' : '-' }}</td>
                                            <td style="text-align: center; width: 50%; padding: 0;">{{ number_format($cgst_amount, 2) }}</td>
                                        </tr>
                                    </table>
                                </td>
                                <td style="border: 1px solid black; padding: 0; text-align: center;">
                                    <table style="width: 100%; border-collapse: collapse; border-spacing: 0; table-layout: fixed;">
                                        <tr>
                                            <td style="border-right: 1px solid black; text-align: center; width: 50%; padding: 0;">{{ $sgst_rate ? $sgst_rate . '%' : '-' }}</td>
                                            <td style="text-align: center; width: 50%; padding: 0;">{{ number_format($sgst_amount, 2) }}</td>
                                        </tr>
                                    </table>
                                </td>
                                <td style="border: 1px solid black; padding: 0; text-align: center;">{{ number_format($total_tax, 2) }}</td>
                            </tr>
                            @endforeach
                            <tr>
                                <td style="border: 1px solid black; padding: 0; text-align: center; font-weight: bold;">Total</td>
                                <td style="border: 1px solid black; padding: 0; text-align: center; font-weight: bold;">{{ number_format($taxable_total, 2) }}</td>
                                <td style="border: 1px solid black; padding: 0; text-align: center; font-weight: bold;">
                                    <table style="width: 100%; border-collapse: collapse; border-spacing: 0; table-layout: fixed;">
                                        <tr>
                                            <td style="border-right: 1px solid black; text-align: center; width: 50%; padding: 0;">-</td>
                                            <td style="text-align: center; width: 50%; padding: 0;">{{ number_format($cgst_total, 2) }}</td>
                                        </tr>
                                    </table>
                                </td>
                                <td style="border: 1px solid black; padding: 0; text-align: center; font-weight: bold;">
                                    <table style="width: 100%; border-collapse: collapse; border-spacing: 0; table-layout: fixed;">
                                        <tr>
                                            <td style="border-right: 1px solid black; text-align: center; width: 50%; padding: 0;">-</td>
                                            <td style="text-align: center; width: 50%; padding: 0;">{{ number_format($sgst_total, 2) }}</td>
                                        </tr>
                                    </table>
                                </td>
                                <td style="border: 1px solid black; padding: 0; text-align: center; font-weight: bold;">{{ number_format($total_tax_amount, 2) }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td colspan="3" style="border: 1px solid black; padding: 5px;">
                        <strong>Tax Amount (in words):</strong> {{ ucwords(numberToWords($voucher->Vouchermeta->sum(function($item) {
                            $amount = $item->item_quantity * ( $item->material_price);
                            return ($amount * $item->gst_percent_rate) / 100 ;
                        }))) }}
                    </td>
                </tr>

                <tr>
                    <td colspan="3" style="border: 1px solid black;">
                        <table style="width: 100%;">
                            <tr>
                                <td style="width: 60%; padding: 5px; vertical-align: top;">
                                    <br><br>
                                </td>
                                <td style="width: 40%; text-align: right; padding: 5px;">
                                    for {{ $voucher->company->company_name }}<br><br><br>
                                    Authorised Signatory
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

            @endif
            </table>

            @if ($totalPages > 1)
                <div class="page-footer">
                    @if ($page < $totalPages)
                        <span>Continued...</span> <br> <br> <br>
                    @endif
                    <span>{{ $page }}/{{ $totalPages }}</span>
                </div>
            @endif
        </div>
    @endfor  
</body>
</html>
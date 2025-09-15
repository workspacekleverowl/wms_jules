<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; }
        .header { text-align: center; }
        .company-name { font-weight: bold; font-size: 20px; }
        .company-address { margin: 5px 0;font-size: 16px; }
        .title { text-align: center; font-weight: bold; margin-top: 10px;font-size: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table, th, td { border: 1px solid #ddd; }
        th { background-color: #f2f2f2; padding: 5px; text-align: left; }
        td { padding: 5px; }
        .text-right { text-align: right; }
        .month-header { background-color: #e0e0e0; font-weight: bold; text-align: center; padding: 8px; margin-top : 12px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="company-name">{{ $monthlyLedgers[0]['summary']['selected_product']['company']['company_name'] }}</div>
        <div class="company-address">
            {{ $monthlyLedgers[0]['summary']['selected_product']['company']['address1'] }}, 
            {{ $monthlyLedgers[0]['summary']['selected_product']['company']['address2'] }}, 
            {{ $monthlyLedgers[0]['summary']['selected_product']['company']['city'] }}
        </div>
        <div class="company-address">
            GST No: {{ $monthlyLedgers[0]['summary']['selected_product']['company']['gst_number'] }}
        </div>

        <hr  style="width: 50%; text-align: center;">
        <div class="company-name">{{ $monthlyLedgers[0]['summary']['selected_product']['name'] }}</div>
        <div class="title">Item Stock Register</div>
        <div class="title">
            {{ \Carbon\Carbon::parse($startDate)->format('d/m/Y') }} -
            {{ \Carbon\Carbon::parse($endDate)->format('d/m/Y') }}
        </div>
    </div>

    @php $grandTotalInwards = 0; $grandTotalOutwards = 0; @endphp

    @foreach($monthlyLedgers as $monthLedger)
        <div class="month-header">{{ $monthLedger['month'] }}</div>
        
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Party</th>
                    <th>Vch Type</th>
                    <th>Vch No.</th>
                    <th class="text-right">Inwards</th>
                    <th class="text-right">Outwards</th>
                    <th class="text-right">Closing</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $monthTotalInwards = 0;
                    $monthTotalOutwards = 0;
                @endphp
                
                @foreach($monthLedger['data'] as $entry)
                    <tr>
                        <td>{{ $entry['transaction_date'] }}</td>
                        <td>{{ $entry['party_name'] }}</td>
                        <td>   @switch($entry['transaction_type'])
                                @case('inward')
                                    Inward
                                    @break
                                @case('outward')
                                    Outward
                                    @break
                                @case('s_inward')
                                    Subcontract Inward
                                    @break
                                @case('s_outward')
                                    Subcontract Outward
                                    @break
                                @case('opening_balance')
                                    Opening Balance
                                    @break    
                                @default
                                    {{ $entry['transaction_type'] }}
                            @endswitch
                        </td>
                        <td>{{ $entry['voucher_no'] }}</td>
                        <td class="text-right">{{ $entry['inward'] }}</td>
                        <td class="text-right">{{ $entry['outward'] }}</td>
                        <td class="text-right">{{ $entry['balance'] }}</td>
                    </tr>
                    @php
                        $monthTotalInwards += $entry['inward'];
                        $monthTotalOutwards += $entry['outward'];
                    @endphp
                @endforeach
                
                <tr>
                    <td colspan="4" class="text-right" style="font-weight: bold;">Month Total and Closing:</td>
                    <td class="text-right" style="font-weight: bold;">{{ $monthTotalInwards }}</td>
                    <td class="text-right" style="font-weight: bold;">{{ $monthTotalOutwards }}</td>
                    <td class="text-right" style="font-weight: bold;">{{ $monthLedger['summary']['closing_balance'] }}</td>
                </tr>
            </tbody>
        </table>

        @php
            $grandTotalInwards += $monthLedger['summary']['total_inward'];
            $grandTotalOutwards += $monthLedger['summary']['total_outward'];
        @endphp
    @endforeach

    <div style="margin-top: 20px; text-align: center;">
        <strong>Grand Total</strong>
        <table>
            <tr>
                <td>Total Inwards</td>
                <td class="text-right">{{ $grandTotalInwards }}</td>
            </tr>
            <tr>
                <td>Total Outwards</td>
                <td class="text-right">{{ $grandTotalOutwards }}</td>
            </tr>
            <tr>
                <td>Final Closing Balance</td>
                <td class="text-right">{{ $monthlyLedgers[count($monthlyLedgers)-1]['summary']['closing_balance'] }}</td>
            </tr>
        </table>
    </div>
</body>
</html>
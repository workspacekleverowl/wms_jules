<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use App\Models\Voucher;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Illuminate\Support\Facades\DB;

class VoucherTransactionsExport implements FromQuery, WithHeadings, WithMapping
{
    protected $tenantId;
    protected $companyId;

    public function __construct($tenantId, $companyId)
    {
        $this->tenantId = $tenantId;
        $this->companyId = $companyId;
    }

    public function query()
    {
        return Voucher::query()
            ->select(
                'voucher.*',
                'voucher_meta.*',
                'party.id as party_id',
                'party.name as party_name',
                'companies.id as company_id',
                'companies.company_name as company_name',
                'products.id as product_id',
                'products.name as product_name',
                'product_category.id as category_id',
                'product_category.name as category_name',
                'financial_year.year as financial_year'
            )
            ->join('voucher_meta', 'voucher.id', '=', 'voucher_meta.voucher_id')
            ->join('party', 'voucher.party_id', '=', 'party.id')
            ->join('companies', 'voucher.company_id', '=', 'companies.id')
            ->join('products', 'voucher_meta.product_id', '=', 'products.id')
            ->join('product_category', 'voucher_meta.category_id', '=', 'product_category.id')
            ->join('financial_year', 'voucher.financial_year_id', '=', 'financial_year.id')
            ->where('voucher.tenant_id', $this->tenantId)
            ->where('voucher.company_id', $this->companyId)
            ->orderBy('voucher.transaction_date', 'desc');
    }

    public function headings(): array
    {
        return [
            'Voucher ID',
            'Voucher No',
            'Transaction Date',
            'Transaction Time',
            'Transaction Type',
            'Vehicle Number',
            'Description',
            'Party ID',
            'Party Name',
            'Company ID',
            'Company Name',
            'Product ID',
            'Product Name',
            'Category ID',
            'Category Name',
            'Product Quantity',
            'Job Work Rate',
            'Material Price',
            'GST Rate',
            'Remark',
            'Financial Year'
        ];
    }

    public function map($row): array
    {
        return [
            $row->id,
            $row->voucher_no,
            $row->transaction_date,
            $row->transaction_time,
            $row->transaction_type,
            $row->vehicle_number,
            $row->description,
            $row->party_id,
            $row->party_name,
            $row->company_id,
            $row->company_name,
            $row->product_id,
            $row->product_name,
            $row->category_id,
            $row->category_name,
            $row->product_quantity,
            $row->job_work_rate,
            $row->material_price,
            $row->gst_percent_rate,
            $row->remark,
            $row->financial_year
        ];
    }
}

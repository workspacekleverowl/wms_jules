<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AcVoucher extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'vouchers';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'date' => 'date',
        'supplier_invoice_date' => 'date',
    ];

    /**
     * Get the company that owns the voucher.
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the voucher entries for the voucher.
     */
    public function voucherEntries()
    {
        return $this->hasMany(AcVoucherEntry::class, 'voucher_id');
    }

    /**
     * Get the invoice items for the voucher.
     */
    public function invoiceItems()
    {
        return $this->hasMany(AcInvoiceItem::class, 'voucher_id');
    }
}

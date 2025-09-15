<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AcInvoiceItem extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'invoice_items';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the voucher that the invoice item belongs to.
     */
    public function voucher()
    {
        return $this->belongsTo(AcVoucher::class, 'voucher_id');
    }

    /**
     * Get the GST rate for the invoice item.
     */
    public function gstRate()
    {
        return $this->belongsTo(AcGstRate::class, 'gst_rate_id');
    }
}

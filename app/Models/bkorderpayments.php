<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class bkorderpayments extends Model
{
    use HasFactory;
    protected $table = 'bk_order_payments';
    protected $guarded = [];

    public function bkorder()
    {
        return $this->belongsTo(bkorder::class,'order_id','id');
    }

    const PAYMENT_METHODS = [
        'cash' => 'Cash',
        'banktransfer' => 'Bank Transfer',
        'creditcard' => 'Credit Card',
        'upi' => 'UPI',
        'cheque' => 'Cheque'
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AcVoucherEntry extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'voucher_entries';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * Get the voucher that the entry belongs to.
     */
    public function voucher()
    {
        return $this->belongsTo(AcVoucher::class, 'voucher_id');
    }

    /**
     * Get the account that the entry belongs to.
     */
    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}

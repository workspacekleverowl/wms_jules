<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Voucher extends Model
{
    use HasFactory;

    protected $table = 'voucher';

    protected $guarded = [];

    

    /**
     * Get the voucher meta records associated with the voucher.
     */
    public function voucherMeta(): HasMany
    {
        return $this->hasMany(Vouchermeta::class, 'voucher_id');
    }

    public function party()
    {
        return $this->belongsTo(party::class, 'party_id');
    }

    public function company()
    {
        return $this->belongsTo(company::class, 'company_id');
    }
}

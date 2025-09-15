<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * Get the company that owns the account.
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the account group that the account belongs to.
     */
    public function accountGroup()
    {
        return $this->belongsTo(AccountGroup::class);
    }

    /**
     * Get the voucher entries for the account.
     */
    public function voucherEntries()
    {
        return $this->hasMany(VoucherEntry::class);
    }
}

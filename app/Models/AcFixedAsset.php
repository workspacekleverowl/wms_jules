<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AcFixedAsset extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'fixed_assets';

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
        'purchase_date' => 'date',
    ];

    /**
     * Get the company that owns the fixed asset.
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the asset ledger account for the fixed asset.
     */
    public function assetLedger()
    {
        return $this->belongsTo(Account::class, 'asset_ledger_id');
    }

    /**
     * Get the depreciation ledger account for the fixed asset.
     */
    public function depreciationLedger()
    {
        return $this->belongsTo(Account::class, 'depreciation_ledger_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SubscriptionPackageDetail extends Model
{
    use HasFactory;
    protected $guarded = [];
    protected $table = 'subscription_package_details';
     protected $casts = [
        'ai_features' => 'boolean',
    ];

    public function package()
    {
        return $this->belongsTo(SubscriptionPackage::class);
    }
}

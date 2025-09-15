<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SubscriptionPackage extends Model
{
    use HasFactory;
    protected $guarded = [];
    protected $table = 'subscription_packages';

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'cancellation_date' => 'date',
    ];

    public function details()
    {
        return $this->hasOne(SubscriptionPackageDetail::class);
    }
}



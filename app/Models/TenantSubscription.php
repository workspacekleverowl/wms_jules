<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TenantSubscription extends Model
{
    use HasFactory;
    protected $guarded = [];
    protected $table = 'tenant_subscriptions';

    // Relationships
    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    public function package()
    {
        return $this->belongsTo(SubscriptionPackage::class, 'subscription_package_id');
    }

    public function payments()
    {
        return $this->hasMany(PaymentTransaction::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;   

class bksupplier extends Model
{
    use HasFactory;
    protected $table = 'bk_supplier';
    protected $guarded = [];

    /**
     * The attributes that should be appended to the model's array form.
     */
    protected $appends = [
        'total_order_amount',
        'total_paid_amount', 
        'total_remaining_amount'
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
    
    public function state()
    {
        return $this->belongsTo(State::class);
    }
    
    public function company()
    {
        return $this->belongsTo(company::class);
    }

     /**
     * Relationship with bkorder for purchase orders
     */
    public function purchaseOrders()
    {
        return $this->hasMany(bkorder::class, 'party_id')->where('order_type', 'purchase');
    }

    /**
     * Get total order amount for this supplier
     */
    public function getTotalOrderAmountAttribute()
    {
        return $this->purchaseOrders()->sum('total_amount') ?? 0;
    }

    /**
     * Get total paid amount for this supplier
     */
    public function getTotalPaidAmountAttribute()
    {
        return $this->purchaseOrders()->sum('paid_amount') ?? 0;
    }

    /**
     * Get total remaining amount for this supplier
     */
    public function getTotalRemainingAmountAttribute()
    {
        $totalAmount = $this->getTotalOrderAmountAttribute();
        $paidAmount = $this->getTotalPaidAmountAttribute();
        
        return $totalAmount - $paidAmount;
    }
}

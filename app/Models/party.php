<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class party extends Model
{
    use HasFactory;

    protected $table = 'party';
    protected $guarded = [];

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
     * Relationship with bkorder for sales orders
     */
    public function salesOrders()
    {
        return $this->hasMany(bkorder::class, 'party_id')->where('order_type', 'sales');
    }

    /**
     * Get total order amount for this party
     */
    public function getTotalOrderAmountAttribute()
    {
        return $this->salesOrders()->sum('total_amount') ?? 0;
    }

    /**
     * Get total paid amount for this party
     */
    public function getTotalPaidAmountAttribute()
    {
        return $this->salesOrders()->sum('paid_amount') ?? 0;
    }

    /**
     * Get total remaining amount for this party
     */
    public function getTotalRemainingAmountAttribute()
    {
        $totalAmount = $this->getTotalOrderAmountAttribute();
        $paidAmount = $this->getTotalPaidAmountAttribute();
        
        return $totalAmount - $paidAmount;
    }
}

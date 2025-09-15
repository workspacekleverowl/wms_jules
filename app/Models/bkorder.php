<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class bkorder extends Model
{
    use HasFactory;
    protected $table = 'bk_order';
    protected $guarded = [];

     // Add the new attributes to the appends array so they're always included
    protected $appends = ['remaining_amount', 'payment_status','can_update'];


    public function bkorderlineitems()
    {
        return $this->hasMany(bkorderlineitems::class,'order_id','id');
    }

    public function payments()
    {
        return $this->hasMany(bkorderpayments::class, 'order_id','id');
    }

    public function orderStockSummary()
    {
        return $this->hasMany(OrderStockSummary::class, 'order_id', 'id');
    }

    // Helper method to get remaining amount
    public function getRemainingAmountAttribute()
    {
        return $this->total_amount - ($this->paid_amount ?? 0);
    }

    // Helper method to get payment status
    public function getPaymentStatusAttribute()
    {
        $paidAmount = $this->paid_amount ?? 0;
        
        if ($paidAmount <= 0) {
            return 'Unpaid';
        } elseif ($paidAmount >= $this->total_amount) {
            return 'Fully Paid';
        } else {
            return 'Partially Paid';
        }
    }

     // Helper method to check if order can be updated
    public function getCanUpdateAttribute()
    {
        // Get all order stock summary records for this order
        $stockSummaries = $this->orderStockSummary;
        
        // If no stock summary records exist, allow updates
        if ($stockSummaries->isEmpty()) {
            return true;
        }
        
        // Check if any line item has been partially returned
        // (original_quantity != available_for_return means some items were returned)
        foreach ($stockSummaries as $summary) {
            if ($summary->original_quantity != $summary->available_for_return) {
                return false;
            }
        }
        
        return true;
    }

    public function company()
    {
        return $this->belongsTo(company::class, 'company_id');
    }
   
}

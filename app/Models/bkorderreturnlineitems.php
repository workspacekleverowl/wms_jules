<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\OrderStockSummary;

class bkorderreturnlineitems extends Model
{
    use HasFactory;

    protected $table = 'bk_order_return_lineitems';

    protected $guarded = [];

    protected $casts = [
        'return_quantity' => 'decimal:3',
        'original_quantity' => 'decimal:3',
        'rate' => 'decimal:2',
        'gst_rate' => 'decimal:2',
        'gst_value' => 'decimal:2',
        'amount' => 'decimal:2',
        'amount_with_gst' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    protected $appends = [
        'available_for_return',
    ];

    // Relationships
    public function bkorderreturn()
    {
        return $this->belongsTo(bkorderreturn::class, 'return_id');
    }

    public function bkorderlineitem()
    {
        return $this->belongsTo(bkorderlineitems::class, 'order_lineitem_id');
    }

    // Accessor for return percentage
    public function getReturnPercentageAttribute()
    {
        if ($this->original_quantity > 0) {
            return round(($this->return_quantity / $this->original_quantity) * 100, 2);
        }
        return 0;
    }

    public function getAvailableForReturnAttribute()
    {
        
        $stockInfo = OrderStockSummary::where('order_lineitem_id', $this->order_lineitem_id)
            ->first();

        return $stockInfo ? $stockInfo->available_for_return : 0;
    }
}

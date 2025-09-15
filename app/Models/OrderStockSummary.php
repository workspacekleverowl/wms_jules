<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderStockSummary extends Model
{
     protected $table = 'vw_order_stock_summary';
    
    // Since this is a view, we don't want timestamps
    public $timestamps = false;
    
    // This is a read-only view
    protected $guarded = ['*'];

    protected $casts = [
        'order_date' => 'date',
        'original_quantity' => 'decimal:3',
        'rate' => 'decimal:2',
        'gst_rate' => 'decimal:2',
        'original_amount' => 'decimal:2',
        'original_amount_with_gst' => 'decimal:2',
        'total_returned_quantity' => 'decimal:3',
        'available_for_return' => 'decimal:3',
        'total_returned_amount' => 'decimal:2',
        'available_amount_for_return' => 'decimal:2'
    ];

    // Relationships
    public function bkorder()
    {
        return $this->belongsTo(bkorder::class, 'order_id');
    }

    public function bkorderlineitem()
    {
        return $this->belongsTo(bkorderlineitems::class, 'order_lineitem_id');
    }

    // Scopes
    public function scopeForTenantCompany($query, $tenantId, $companyId)
    {
        return $query->where('tenant_id', $tenantId)->where('company_id', $companyId);
    }

    public function scopeByOrderType($query, $orderType)
    {
        return $query->where('order_type', $orderType);
    }

    public function scopeAvailableForReturn($query)
    {
        return $query->where('available_for_return', '>', 0);
    }

    public function scopeByReturnStatus($query, $status)
    {
        return $query->where('return_status', $status);
    }
}

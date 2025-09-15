<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class bkorderreturn extends Model
{
     use HasFactory;

    protected $table = 'bk_order_return';

    protected $guarded = [];

    protected $casts = [
        'return_date' => 'date',
        'total_return_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // Constants for return types
    const RETURN_TYPES = [
        'full' => 'Full Return',
        'partial' => 'Partial Return'
    ];

    // Constants for status
    const STATUSES = [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'processed' => 'Processed'
    ];

    // Relationships
    public function bkorder()
    {
        return $this->belongsTo(bkorder::class, 'order_id');
    }

    public function bkorderreturnlineitems()
    {
        return $this->hasMany(bkorderreturnlineitems::class, 'return_id');
    }

    // Accessor for return type name
    public function getReturnTypeNameAttribute()
    {
        return self::RETURN_TYPES[$this->return_type] ?? $this->return_type;
    }

    // Accessor for status name
    public function getStatusNameAttribute()
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    // Scope for filtering by tenant and company
    public function scopeForTenantCompany($query, $tenantId, $companyId)
    {
        return $query->where('tenant_id', $tenantId)->where('company_id', $companyId);
    }

    // Scope for filtering by status
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    // Scope for filtering by return type
    public function scopeByReturnType($query, $returnType)
    {
        return $query->where('return_type', $returnType);
    }
}

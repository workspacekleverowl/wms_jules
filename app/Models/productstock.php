<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class productstock extends Model
{
    use HasFactory;

    protected $table = 'product_stock';

    protected $guarded = [];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
    public function company()
    {
        return $this->belongsTo(company::class);
    }
    
    public function products()
    {
        return $this->belongsTo(Product::class);
    }
}

<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Itemcategory extends Model
{
    use HasFactory;

    protected $table = 'item_category';
    protected $guarded = [];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
    public function company()
    {
        return $this->belongsTo(company::class);
    }
      
    public function item()
    {
        return $this->hasMany(Item::class, 'category_id');
    }
}

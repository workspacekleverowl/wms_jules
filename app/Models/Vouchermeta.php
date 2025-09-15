<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Vouchermeta extends Model
{
    use HasFactory;

    protected $table = 'voucher_meta';

    protected $guarded = [];

    /**
     * Get the related voucher.
     */
    public function voucher()
    {
        return $this->belongsTo(Voucher::class,'voucher_id');
    }

    /**
     * Get the related product.
     */
    public function Item()
    {
        return $this->belongsTo(Item::class,'item_id');
    }

    /**
     * Get the related category.
     */
    public function category()
    {
        return $this->belongsTo(Itemcategory::class,'category_id');
    }
}

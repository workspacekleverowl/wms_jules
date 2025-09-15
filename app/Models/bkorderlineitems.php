<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class bkorderlineitems extends Model
{
    use HasFactory;
    protected $table = 'bk_order_lineitems';
    protected $guarded = [];

    public function bkorder()
    {
        return $this->belongsTo(bkorder::class,'order_id', 'id');
    }
}

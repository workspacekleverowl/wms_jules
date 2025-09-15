<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Itemmeta extends Model
{
    protected $table = 'item_meta';
    protected $guarded = [];
    public function Item()
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

}

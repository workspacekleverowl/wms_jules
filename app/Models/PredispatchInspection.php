<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;   

class PredispatchInspection extends Model
{
    use HasFactory;
    protected $table = 'predispatch_inspection';
    protected $guarded = [];

    public function party()
    {
        return $this->belongsTo(party::class, 'party_id');
    }

    public function Item()
    {
        return $this->belongsTo(Item::class,'item_id');
    }
 
    
}

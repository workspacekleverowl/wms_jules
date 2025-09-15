<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class quotation extends Model
{
    use HasFactory;
    protected $table = 'quotation';
    protected $guarded = [];

    public function quotationlineitems()
    {
        return $this->hasMany(quotationlineitems::class);
    }
}

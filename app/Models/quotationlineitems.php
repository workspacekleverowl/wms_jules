<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class quotationlineitems extends Model
{
    use HasFactory;
    protected $table = 'quotation_lineitems';
    protected $guarded = [];

    public function quotation()
    {
        return $this->belongsTo(quotation::class);
    }
}

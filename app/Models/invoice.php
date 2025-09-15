<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class invoice extends Model
{
    use HasFactory;
    protected $table = 'invoice';
    protected $guarded = [];

    public function invoicelineitems()
    {
        return $this->hasMany(invoicelineitems::class);
    }
}

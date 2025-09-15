<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class invoicelineitems extends Model
{
    use HasFactory;
    protected $table = 'invoice_lineitems';
    protected $guarded = [];

    public function invoice()
    {
        return $this->belongsTo(invoice::class);
    }
}

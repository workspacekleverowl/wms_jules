<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class bkexpense extends Model
{
    use HasFactory;
    protected $table = 'bk_expense';
    protected $guarded = [];

    public function expenseType()
    {
        return $this->belongsTo(bkexpensetype::class, 'expensetype_id', 'id');
    }
}

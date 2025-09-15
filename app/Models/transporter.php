<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class transporter extends Model
{
    use HasFactory;

    protected $table = 'transporter';
    protected $guarded = [];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

}

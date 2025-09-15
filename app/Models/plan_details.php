<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class plan_details extends Model
{
   
    protected $table = 'plan_details';
    protected $guarded = [];

    protected $casts = [
        'ai_features' => 'boolean',
    ];

    public function package()
    {
        return $this->belongsTo(Plan::class);
    }
}

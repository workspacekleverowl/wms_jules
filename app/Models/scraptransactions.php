<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class scraptransactions extends Model
{
    use HasFactory;
    protected $table = 'scrap_transactions';
    protected $guarded = [];

    protected $appends = ['party_name'];
    protected $hidden = ['party'];
    public function party()
    {
        return $this->belongsTo(party::class, 'party_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
    
    public function company()
    {
        return $this->belongsTo(company::class);
    }

    public function getPartyNameAttribute()
    {
        return $this->party ? $this->party->name : null;
    }
}

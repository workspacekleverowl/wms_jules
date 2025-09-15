<?php

namespace App\Models;

use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant
{
    protected $guarded = [];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function usersettings()
    {
        return $this->hasMany(usersettings::class);
    }

}
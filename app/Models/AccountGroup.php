<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountGroup extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * Get the company that owns the account group.
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the parent account group.
     */
    public function parent()
    {
        return $this->belongsTo(AccountGroup::class, 'parent_id');
    }

    /**
     * Get the child account groups.
     */
    public function children()
    {
        return $this->hasMany(AccountGroup::class, 'parent_id');
    }

    /**
     * Get the accounts for the account group.
     */
    public function accounts()
    {
        return $this->hasMany(Account::class);
    }
}

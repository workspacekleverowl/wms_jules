<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Advance extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'employee_id',
        'advance_date',
        'advance_amount',
        'monthly_installment',
        'status',
        'notes',
        'tenant_id',
        'company_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'advance_date' => 'date',
        'advance_amount' => 'decimal:2',
        'monthly_installment' => 'decimal:2',
    ];

    /**
     * Get the employee that this advance belongs to.
     */
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the repayments for the advance.
     */
    public function repayments()
    {
        return $this->hasMany(AdvanceRepayment::class);
    }
}

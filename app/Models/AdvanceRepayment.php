<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AdvanceRepayment extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'advance_repayments';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'advance_id',
        'payment_date',
        'amount_paid',
        'payment_type',
        'status',
        'salary_slip_id',
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
        'payment_date' => 'date',
        'amount_paid' => 'decimal:2',
    ];

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the advance that this repayment belongs to.
     */
    public function advance()
    {
        return $this->belongsTo(Advance::class);
    }

    /**
     * Get the salary slip that this repayment might be linked to.
     */
    public function salarySlip()
    {
        return $this->belongsTo(SalarySlip::class);
    }
}

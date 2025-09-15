<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class SalarySlipDetail extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'salary_slip_details';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'salary_slip_id',
        'component_id',
        'amount',
        'component_name',
        'component_type',
        'tenant_id',
        'company_id',
        'can_edit'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /**
     * Get the salary slip that this detail belongs to.
     */
    public function salarySlip()
    {
        return $this->belongsTo(SalarySlip::class);
    }

    /**
     * Get the salary component that this detail might be linked to.
     */
    public function component()
    {
        return $this->belongsTo(SalaryComponent::class, 'component_id');
    }
}

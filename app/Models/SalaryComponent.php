<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SalaryComponent extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'salary_components';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'type',
        'calculation_type',
        'value',
        'parent_id',
        'is_active',
        'tenant_id',
        'company_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'value' => 'decimal:2',
    ];

    /**
     * Get the parent component.
     */
    public function parent()
    {
        return $this->belongsTo(SalaryComponent::class, 'parent_id');
    }

    /**
     * Get the child components.
     */
    public function children()
    {
        return $this->hasMany(SalaryComponent::class, 'parent_id');
    }

    /**
     * The employees that belong to the salary component.
     */
    public function employees()
    {
        return $this->belongsToMany(Employee::class, 'employee_salary_components', 'component_id', 'employee_id')
                    ->using(EmployeeSalaryComponent::class)
                    ->withPivot('custom_value')
                    ->withTimestamps();
    }
}

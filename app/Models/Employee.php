<?php

namespace  App\Models; 

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Employee extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'employee_id',
        'first_name',
        'last_name',
        'email',
        'phone_number',
        'hire_date',
        'termination_date',
        'date_of_birth',
        'gender',
        'address',
        'status',
        'department_id',
        'designation_id',
        'basic_salary',
        'bank_name',
        'bank_ifsc',
        'branch_location',
        'tenant_id',
        'company_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'hire_date' => 'date',
        'termination_date' => 'date',
        'date_of_birth' => 'date',
        'basic_salary' => 'decimal:2',
    ];

    /**
     * Get the department that the employee belongs to.
     */
    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Get the designation that the employee belongs to.
     */
    public function designation()
    {
        return $this->belongsTo(Designation::class);
    }

    /**
     * Get the attendance records for the employee.
     */
    public function attendanceRecords()
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    /**
     * The salary components that belong to the employee.
     */
    public function salaryComponents()
    {
        return $this->belongsToMany(SalaryComponent::class, 'employee_salary_components', 'employee_id', 'component_id')
                    ->using(EmployeeSalaryComponent::class)
                    ->withPivot('custom_value')
                    ->withTimestamps();
    }

    /**
     * Get the payments for the employee.
     */
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Get the advances for the employee.
     */
    public function advances()
    {
        return $this->hasMany(Advance::class);
    }

    /**
     * Get the salary slips for the employee.
     */
    public function salarySlips()
    {
        return $this->hasMany(SalarySlip::class);
    }

    /**
     * Get the documents for the employee.
     */
    public function documents()
    {
        return $this->hasMany(EmployeeDocument::class);
    }
}

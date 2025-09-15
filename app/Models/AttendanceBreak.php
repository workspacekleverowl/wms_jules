<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AttendanceBreak extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'attendance_breaks';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'attendance_record_id',
        'break_start_time',
        'break_end_time',
        'tenant_id',
        'company_id', // Added company_id
    ];
    
    /**
     * We don't need timestamps for this model.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the attendance record that this break belongs to.
     */
    public function attendanceRecord()
    {
        return $this->belongsTo(AttendanceRecord::class);
    }
}

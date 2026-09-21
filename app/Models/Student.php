<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'student_number',
        'course',
        'section',
        'company_id',
        'internship_schedule',
        'supervisor_id',
        'internship_status',
        'required_hours',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function supervisor()
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    public function internshipReports()
    {
        return $this->hasMany(InternshipReport::class);
    }

    public function evaluations()
    {
        return $this->hasMany(Evaluation::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Evaluation extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'company_id',
        'supervisor_id',
        'evaluation_period_start',
        'evaluation_period_end',
        'overall_rating',
        'strengths',
        'areas_for_improvement',
        'recommendations',
        'supervisor_remarks',
        'status',
        'submitted_at',
        'locked_at',
        'locked_by',
    ];

    protected function casts(): array
    {
        return [
            'evaluation_period_start' => 'date',
            'evaluation_period_end' => 'date',
            'overall_rating' => 'decimal:2',
            'submitted_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function supervisor()
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function lockedBy()
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function responses()
    {
        return $this->hasMany(EvaluationResponse::class);
    }
}

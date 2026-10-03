<?php

namespace App\Models;

use App\Models\Concerns\VisibleThroughStudent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use HasFactory, VisibleThroughStudent;

    protected $fillable = [
        'student_id',
        'date',
        'time_in',
        'time_in_latitude',
        'time_in_longitude',
        'time_in_accuracy',
        'time_in_mocked',
        'time_in_photo_path',
        'time_out',
        'time_out_latitude',
        'time_out_longitude',
        'time_out_accuracy',
        'time_out_mocked',
        'time_out_photo_path',
        'rendered_hours',
        'recorded_by',
        'time_in_status',
        'time_in_rejection_reason',
        'time_out_status',
        'time_out_rejection_reason',
        'is_emergency',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'time_in_mocked' => 'boolean',
            'time_out_mocked' => 'boolean',
        ];
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}

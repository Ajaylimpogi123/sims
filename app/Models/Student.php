<?php

namespace App\Models;

use App\Services\AttendanceService;
use App\Services\InternshipReportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

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

    /**
     * Students the given user may see in a list: a Supervisor only their own
     * students (students.supervisor_id), a Student only themself,
     * Coordinator/Administrator everyone. Mirrors StudentPolicy::view().
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return match ((int) $user->role_id) {
            User::ROLE_COORDINATOR, User::ROLE_ADMIN => $query,
            User::ROLE_SUPERVISOR => $query->where($this->qualifyColumn('supervisor_id'), $user->id),
            User::ROLE_STUDENT => $query->where($this->qualifyColumn('user_id'), $user->id),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Snapshot of the private files this student owns (report attachments
     * and attendance photos).
     *
     * Deleting a user cascades users -> students -> attendances /
     * internship_reports at the FK level, so no model events fire and the
     * files would be orphaned. Any code path that deletes a student (or its
     * user) must take this snapshot *before* the delete — the report rows
     * are gone afterwards — and pass it to deleteStoredFiles() only once the
     * delete has succeeded.
     *
     * @return array{attachments: list<string>, photo_directory: string}
     */
    public function storedFiles(): array
    {
        return [
            'attachments' => $this->internshipReports()
                ->whereNotNull('attachment_path')
                ->pluck('attachment_path')
                ->all(),
            'photo_directory' => "attendance-photos/{$this->id}",
        ];
    }

    /**
     * @param  array{attachments: list<string>, photo_directory: string}  $storedFiles
     */
    public static function deleteStoredFiles(array $storedFiles): void
    {
        if ($storedFiles['attachments'] !== []) {
            Storage::disk(InternshipReportService::ATTACHMENT_DISK)
                ->delete($storedFiles['attachments']);
        }

        Storage::disk(AttendanceService::PHOTO_DISK)
            ->deleteDirectory($storedFiles['photo_directory']);
    }
}

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
        'term_id',
        'student_number',
        'course',
        'section',
        'company_id',
        'internship_schedule',
        'supervisor_id',
        'internship_status',
        'required_hours',
    ];

    /**
     * The only internship_status in which the student may submit their own
     * attendance and reports (AttendanceService / InternshipReportService
     * enforce it; staff actions don't depend on it).
     */
    public const SUBMITTING_STATUS = 'ongoing';

    /** Refusal for a student whose term is no longer the current one. */
    public const TERM_ENDED_MESSAGE = 'Your internship term has ended.';

    /** Refusal for a student enrolled in an upcoming (not yet current) term. */
    public const TERM_NOT_STARTED_MESSAGE = 'Your internship term hasn\'t started yet.';

    /**
     * Whether the student may submit attendance and reports right now:
     * an Ongoing internship in the current term.
     */
    public function canSubmitWork(): bool
    {
        return $this->submissionRefusal('') === null;
    }

    /**
     * Why the student can't submit right now (null when they can): their
     * term has ended, or the internship isn't Ongoing ($notOngoingMessage).
     */
    public function submissionRefusal(string $notOngoingMessage): ?string
    {
        return match (true) {
            ! $this->inCurrentTerm() => $this->inPastTerm() || Term::currentId() === null
                ? self::TERM_ENDED_MESSAGE
                : self::TERM_NOT_STARTED_MESSAGE,
            $this->internship_status !== self::SUBMITTING_STATUS => $notOngoingMessage,
            default => null,
        };
    }

    /**
     * Whether this enrollment belongs to a past (archived) term, one before
     * the current term (read-only but for the Administrator).
     */
    public function inPastTerm(): bool
    {
        return (bool) $this->term?->isPast();
    }

    /**
     * Whether this enrollment belongs to the current term.
     */
    public function inCurrentTerm(): bool
    {
        $currentId = Term::currentId();

        return $currentId !== null && (int) $this->term_id === $currentId;
    }

    /**
     * The `submission` object both UIs read to enable / disable the
     * student's submit actions up front: whether submitting is open, the
     * status it depends on, and the refusal message (null when open).
     *
     * @return array{can_submit: bool, internship_status: string|null, reason: string|null}
     */
    public function submissionState(string $refusalMessage): array
    {
        $reason = $this->submissionRefusal($refusalMessage);

        return [
            'can_submit' => $reason === null,
            'internship_status' => $this->internship_status,
            'reason' => $reason,
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function term()
    {
        return $this->belongsTo(Term::class);
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
     * Enrollments in the given term; a null term (none exists yet) leaves
     * the query unscoped.
     */
    public function scopeInTerm(Builder $query, ?int $termId): Builder
    {
        return $termId === null ? $query : $query->where($this->qualifyColumn('term_id'), $termId);
    }

    /**
     * Enrollments in the current or an upcoming term (not a past one).
     */
    public function scopeInOpenTerm(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('term_id'), Term::openIds());
    }

    /**
     * Enrollments in the current term (company slots count only these).
     */
    public function scopeInCurrentTerm(Builder $query): Builder
    {
        return $query->inTerm(Term::currentId());
    }

    /**
     * Students placed at a company who have no working supervisor: none
     * assigned (the company has no Company Supervisor), one whose account
     * no longer holds the Supervisor role (a role change in User Management
     * leaves supervisor_id behind), or one whose account is inactive. The
     * same rows as the
     * `needs_supervisor` flag InternshipAssignmentService::loadSupervisorFlags()
     * sets; the dashboard's "need a supervisor" action item counts these.
     */
    public function scopeNeedsSupervisor(Builder $query): Builder
    {
        return $query
            ->whereNotNull($this->qualifyColumn('company_id'))
            ->where(fn (Builder $q) => $q
                ->whereNull($this->qualifyColumn('supervisor_id'))
                ->orWhereDoesntHave('supervisor', fn (Builder $user) => $user
                    ->where('role_id', User::ROLE_SUPERVISOR)
                    ->where('status', 'active')));
    }

    /**
     * The Attendance / Progress Monitoring list for the given user (website
     * and mobile API): the students they may see, newest profile first,
     * each with `total_rendered_hours` (sum of credited hours, null when
     * none). The lists add ->inTerm() for the viewed term; single-student
     * reads don't (a past term's student stays readable).
     */
    public function scopeMonitoredBy(Builder $query, User $user): Builder
    {
        return $query
            ->withSum('attendances as total_rendered_hours', 'rendered_hours')
            ->visibleTo($user)
            ->orderBy($this->qualifyColumn('created_at'), 'desc');
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

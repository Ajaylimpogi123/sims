<?php

namespace App\Services;

use App\Models\InternshipReport;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Internship report write logic shared by the website and the mobile API:
 * a student's create / update / delete (with the optional attachment), and
 * the Coordinator/Admin review.
 *
 * Authorization is the caller's job (InternshipReportPolicy).
 */
class InternshipReportService
{
    /**
     * Attachments are stored on the private `local` disk (not `public`) and
     * only ever served back out through an authorized download route —
     * never via a direct /storage/... URL, which would bypass authorization.
     */
    public const ATTACHMENT_DISK = 'local';

    /** Report fields a student fills in (the attachment is handled apart). */
    private const FIELDS = ['type', 'period_start', 'period_end', 'content'];

    public function __construct(private NotificationService $notifications) {}

    public static function rules(): array
    {
        return [
            'type' => ['required', 'in:daily,weekly'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'content' => ['required', 'string'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    public static function reviewRules(): array
    {
        return [
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @param  array{type: string, period_start: string, period_end: string, content: string}  $data  validated rules() data
     *
     * @throws ValidationException when a report of this type already exists for the period
     */
    public function create(Student $student, array $data, ?UploadedFile $attachment = null): InternshipReport
    {
        $this->guardAgainstDuplicate($student, $data);

        $attributes = $this->fields($data);

        if ($attachment) {
            $attributes['attachment_path'] = $attachment->store('report-attachments', self::ATTACHMENT_DISK);
            $attributes['attachment_original_name'] = $attachment->getClientOriginalName();
        }

        $attributes['status'] = 'pending';

        $report = $student->internshipReports()->create($attributes);

        $this->notifications->reportSubmitted($report);

        return $report;
    }

    /**
     * A new attachment replaces the old one; without one the existing
     * attachment is kept.
     *
     * @param  array{type: string, period_start: string, period_end: string, content: string}  $data  validated rules() data
     *
     * @throws ValidationException when another report of this type already exists for the period
     */
    public function update(InternshipReport $report, array $data, ?UploadedFile $attachment = null): InternshipReport
    {
        $this->guardAgainstDuplicate($report->student, $data, $report->id);

        $attributes = $this->fields($data);

        if ($attachment) {
            if ($report->attachment_path) {
                Storage::disk(self::ATTACHMENT_DISK)->delete($report->attachment_path);
            }

            $attributes['attachment_path'] = $attachment->store('report-attachments', self::ATTACHMENT_DISK);
            $attributes['attachment_original_name'] = $attachment->getClientOriginalName();
        }

        $report->update($attributes);

        return $report;
    }

    public function delete(InternshipReport $report): void
    {
        if ($report->attachment_path) {
            Storage::disk(self::ATTACHMENT_DISK)->delete($report->attachment_path);
        }

        $report->delete();
    }

    public function review(InternshipReport $report, User $reviewer, ?string $comment): InternshipReport
    {
        $report->update([
            'status' => 'reviewed',
            'reviewer_comment' => $comment,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $this->notifications->reportReviewed($report);

        return $report;
    }

    private function fields(array $data): array
    {
        return array_intersect_key($data, array_flip(self::FIELDS));
    }

    /**
     * @throws ValidationException
     */
    private function guardAgainstDuplicate(Student $student, array $data, ?int $ignoreReportId = null): void
    {
        $duplicateExists = $student->internshipReports()
            ->where('period_start', $data['period_start'])
            ->where('type', $data['type'])
            ->when($ignoreReportId, fn ($query) => $query->where('id', '!=', $ignoreReportId))
            ->exists();

        if ($duplicateExists) {
            throw ValidationException::withMessages([
                'period_start' => "You've already submitted a {$data['type']} report for this period.",
            ]);
        }
    }
}

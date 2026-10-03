<?php

namespace App\Services;

use App\Exceptions\ReportRuleException;
use App\Models\InternshipReport;
use App\Models\Student;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Internship report write logic shared by the website and the mobile API:
 * a student's create / update / delete (with the optional attachment), and
 * the Coordinator/Admin review.
 *
 * Authorization is the caller's job (InternshipReportPolicy); this class
 * re-checks the "pending only" rule under a row lock and owns the files.
 */
class InternshipReportService
{
    /**
     * Attachments are stored on the private `local` disk (not `public`) and
     * only ever served back out through an authorized download route —
     * never via a direct /storage/... URL, which would bypass authorization.
     */
    public const ATTACHMENT_DISK = 'local';

    public const ATTACHMENT_DIRECTORY = 'report-attachments';

    /** Kilobytes. */
    public const MAX_ATTACHMENT_SIZE = 5120;

    /** Pixels per side for image attachments. */
    public const MAX_IMAGE_DIMENSION = 8000;

    /**
     * Characters. `content` is a MySQL TEXT column (65,535 bytes); at up to
     * 4 bytes per utf8mb4 character this always fits.
     */
    public const MAX_CONTENT_LENGTH = 16000;

    /** attachment_original_name is a VARCHAR(255). */
    private const MAX_NAME_LENGTH = 255;

    public const NOT_PENDING_MESSAGE = 'This report has already been reviewed and can no longer be changed.';

    public const ALREADY_REVIEWED_MESSAGE = 'This report has already been reviewed.';

    /** Report fields a student fills in (the attachment is handled apart). */
    private const FIELDS = ['type', 'period_start', 'period_end', 'content'];

    /** Client file extensions accepted as-is for each stored (content-based) extension. */
    private const NAME_EXTENSIONS = [
        'jpg' => ['jpg', 'jpeg'],
        'jpeg' => ['jpg', 'jpeg'],
        'png' => ['png'],
        'pdf' => ['pdf'],
    ];

    public function __construct(private NotificationService $notifications) {}

    public static function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:daily,weekly'],
            'period_start' => ['bail', 'required', 'string', 'date_format:Y-m-d'],
            'period_end' => [
                'bail',
                'required',
                'string',
                'date_format:Y-m-d',
                // Compared only against a string period_start: the date
                // comparison throws a TypeError (500) on an array.
                Rule::when(fn ($input) => is_string($input->period_start), ['after_or_equal:period_start']),
                // A daily report covers exactly one day.
                Rule::when(fn ($input) => is_string($input->period_start) && $input->type === 'daily', ['same:period_start']),
            ],
            'content' => ['required', 'string', 'max:'.self::MAX_CONTENT_LENGTH],
            // `mimes` checks the file's actual content, so SVG / HTML (or
            // anything renamed to .jpg / .pdf) is refused.
            'attachment' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:'.self::MAX_ATTACHMENT_SIZE,
                self::imageWithinDimensions(...),
            ],
        ];
    }

    public static function reviewRules(): array
    {
        return [
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Images are capped at MAX_IMAGE_DIMENSION per side, so a tiny, highly
     * compressed file can't decode to hundreds of megapixels in a reviewer's
     * browser or the app. PDFs are not images and skip this check.
     */
    private static function imageWithinDimensions(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! str_starts_with((string) $value->getMimeType(), 'image/')) {
            return;
        }

        $size = @getimagesize($value->getRealPath());

        if ($size === false) {
            $fail('The :attribute must be a valid image.');

            return;
        }

        if ($size[0] > self::MAX_IMAGE_DIMENSION || $size[1] > self::MAX_IMAGE_DIMENSION) {
            $max = self::MAX_IMAGE_DIMENSION;
            $fail("The :attribute may not be larger than {$max} x {$max} pixels.");
        }
    }

    /**
     * @param  array{type: string, period_start: string, period_end: string, content: string}  $data  validated rules() data
     *
     * @throws ValidationException when a report of this type already exists for the period
     */
    public function create(Student $student, array $data, ?UploadedFile $attachment = null): InternshipReport
    {
        $attributes = $this->fields($data);

        // Checked before any file is written; the unique index settles races.
        $this->guardAgainstDuplicate($student, $attributes);

        $stored = $attachment ? $this->storeAttachment($attachment) : [];

        try {
            $report = $student->internshipReports()->create([
                ...$attributes,
                ...$stored,
                'status' => 'pending',
            ]);
        } catch (Throwable $e) {
            $this->deleteFile($stored['attachment_path'] ?? null);

            throw $e instanceof UniqueConstraintViolationException ? self::duplicate($attributes['type']) : $e;
        }

        $this->notifications->reportSubmitted($report);

        return $report;
    }

    /**
     * A new attachment replaces the old one; `$removeAttachment` (mobile app
     * only) drops it without a replacement; otherwise it is kept. The
     * pending rule is re-checked under a row lock, so a review that lands
     * first wins, and the replaced file is deleted only after commit.
     *
     * @param  array{type: string, period_start: string, period_end: string, content: string}  $data  validated rules() data
     *
     * @throws ValidationException when another report of this type already exists for the period
     * @throws ReportRuleException when the report is no longer pending
     * @throws ModelNotFoundException when the report was deleted meanwhile
     */
    public function update(InternshipReport $report, array $data, ?UploadedFile $attachment = null, bool $removeAttachment = false): InternshipReport
    {
        $attributes = $this->fields($data);

        $this->guardAgainstDuplicate($report->student, $attributes, $report->id);

        $stored = match (true) {
            $attachment !== null => $this->storeAttachment($attachment),
            $removeAttachment => ['attachment_path' => null, 'attachment_original_name' => null],
            default => [],
        };
        $newPath = $attachment !== null ? $stored['attachment_path'] : null;

        try {
            $oldPath = DB::transaction(function () use ($report, $attributes, $stored) {
                $current = $this->lockPending($report);
                $oldPath = $current->attachment_path;

                $current->fill([...$attributes, ...$stored])->save();
                $report->setRawAttributes($current->getAttributes(), true);

                return array_key_exists('attachment_path', $stored) ? $oldPath : null;
            });
        } catch (Throwable $e) {
            $this->deleteFile($newPath);

            throw $e instanceof UniqueConstraintViolationException ? self::duplicate($attributes['type']) : $e;
        }

        if ($oldPath !== $newPath) {
            $this->deleteFile($oldPath);
        }

        return $report;
    }

    /**
     * @throws ReportRuleException when the report is no longer pending
     * @throws ModelNotFoundException when the report was deleted meanwhile
     */
    public function delete(InternshipReport $report): void
    {
        $path = DB::transaction(function () use ($report) {
            $current = $this->lockPending($report);
            $current->delete();

            return $current->attachment_path;
        });

        $this->deleteFile($path);
    }

    /**
     * Review a pending report, once. The row is locked, so a concurrent
     * student delete/edit or a second reviewer can't interleave; the
     * student is notified only after commit.
     *
     * @throws ReportRuleException when it was already reviewed
     * @throws ModelNotFoundException when it was deleted meanwhile
     */
    public function review(InternshipReport $report, User $reviewer, ?string $comment): InternshipReport
    {
        DB::transaction(function () use ($report, $reviewer, $comment) {
            $current = InternshipReport::query()->lockForUpdate()->find($report->id)
                ?? throw (new ModelNotFoundException)->setModel(InternshipReport::class, [$report->id]);

            if ($current->status !== 'pending') {
                throw new ReportRuleException(self::ALREADY_REVIEWED_MESSAGE);
            }

            $current->update([
                'status' => 'reviewed',
                'reviewer_comment' => $comment,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            $report->setRawAttributes($current->getAttributes(), true);
        });

        $this->notifications->reportReviewed($report);

        return $report;
    }

    /**
     * The report's row, locked for the rest of the transaction, provided it
     * still exists and is still pending.
     */
    private function lockPending(InternshipReport $report): InternshipReport
    {
        $current = InternshipReport::query()->lockForUpdate()->find($report->id)
            ?? throw (new ModelNotFoundException)->setModel(InternshipReport::class, [$report->id]);

        if ($current->status !== 'pending') {
            throw new ReportRuleException(self::NOT_PENDING_MESSAGE);
        }

        return $current;
    }

    /**
     * @return array{attachment_path: string, attachment_original_name: string}
     */
    private function storeAttachment(UploadedFile $attachment): array
    {
        return [
            'attachment_path' => $attachment->store(self::ATTACHMENT_DIRECTORY, self::ATTACHMENT_DISK),
            'attachment_original_name' => self::displayName($attachment),
        ];
    }

    /**
     * The client's file name, made safe to store and to send back in a
     * Content-Disposition header: valid UTF-8, no control characters, at
     * most 255 characters, and an extension matching the file's actual
     * type (so "notes.html" holding a PDF is offered as "notes.pdf").
     */
    public static function displayName(UploadedFile $file): string
    {
        $extension = strtolower((string) $file->guessExtension());

        $name = mb_scrub((string) $file->getClientOriginalName(), 'UTF-8');
        $name = trim((string) preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]+/u', '', $name));

        $dot = mb_strrpos($name, '.');
        $base = $dot === false ? $name : mb_substr($name, 0, $dot);
        $clientExtension = $dot === false ? '' : mb_strtolower(mb_substr($name, $dot + 1));

        if (in_array($clientExtension, self::NAME_EXTENSIONS[$extension] ?? [], true)) {
            $extension = $clientExtension;
        }

        $suffix = $extension === '' ? '' : '.'.$extension;
        $base = trim(mb_substr(trim($base), 0, self::MAX_NAME_LENGTH - mb_strlen($suffix)));

        return ($base === '' ? 'attachment' : $base).$suffix;
    }

    private function deleteFile(?string $path): void
    {
        if ($path) {
            Storage::disk(self::ATTACHMENT_DISK)->delete($path);
        }
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
            throw self::duplicate($data['type']);
        }
    }

    private static function duplicate(string $type): ValidationException
    {
        return ValidationException::withMessages([
            'period_start' => "You've already submitted a {$type} report for this period.",
        ]);
    }
}

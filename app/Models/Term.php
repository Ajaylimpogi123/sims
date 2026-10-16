<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A School Year + Semester (owner feature, 2026-10-16). Exactly one term is
 * current (TermService::setCurrent keeps it that way); a student row is one
 * OJT enrollment in one term (students.term_id), and attendance, reports
 * and evaluations inherit the term through their student.
 *
 * Past (non-current) terms are read-only for everyone but the
 * Administrator (App\Support\TermGuard).
 */
class Term extends Model
{
    use HasFactory;

    /** semester values with their labels, in display order. */
    public const SEMESTERS = [
        'first' => '1st Semester',
        'second' => '2nd Semester',
        'summer' => 'Summer',
    ];

    /** Container key of the per-request current-term memo. */
    private const CURRENT_KEY = 'sims.current-term';

    protected $fillable = [
        'school_year_start',
        'semester',
        'starts_on',
        'ends_on',
        'is_current',
    ];

    protected $appends = ['label'];

    protected function casts(): array
    {
        return [
            'school_year_start' => 'integer',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'is_current' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::flushCurrent());
        static::deleted(fn () => static::flushCurrent());
    }

    public function students()
    {
        return $this->hasMany(Student::class);
    }

    /**
     * "SY 2026-2027 · 1st Semester".
     */
    public function getLabelAttribute(): string
    {
        return static::labelFor((int) $this->school_year_start, (string) $this->semester);
    }

    public static function labelFor(int $schoolYearStart, string $semester): string
    {
        return 'SY '.$schoolYearStart.'-'.($schoolYearStart + 1).' · '.(self::SEMESTERS[$semester] ?? $semester);
    }

    public function getSemesterLabelAttribute(): string
    {
        return self::SEMESTERS[$this->semester] ?? (string) $this->semester;
    }

    /**
     * The current term, or null when none is marked current. Memoized in
     * the container (a fresh one per test), flushed whenever a term is
     * saved or deleted.
     */
    public static function current(): ?self
    {
        $app = app();

        if (! $app->bound(self::CURRENT_KEY)) {
            $app->instance(self::CURRENT_KEY, [
                'term' => static::query()->where('is_current', true)->orderByDesc('id')->first(),
            ]);
        }

        return $app->make(self::CURRENT_KEY)['term'];
    }

    public static function currentId(): ?int
    {
        return static::current()?->id;
    }

    public static function flushCurrent(): void
    {
        app()->forgetInstance(self::CURRENT_KEY);
    }

    public function isCurrent(): bool
    {
        return (int) $this->id === (int) static::currentId();
    }

    /**
     * Newest first: school year descending, then summer, 2nd, 1st semester.
     */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query
            ->orderByDesc('school_year_start')
            ->orderByRaw("case semester when 'summer' then 0 when 'second' then 1 else 2 end")
            ->orderByDesc('id');
    }

    /**
     * {id, label, is_current}: the minimal shape the UIs show.
     *
     * @return array{id: int, label: string, is_current: bool}
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'is_current' => $this->isCurrent(),
        ];
    }
}

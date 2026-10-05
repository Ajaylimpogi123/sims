<?php

namespace App\Services;

use App\Exceptions\EvaluationRuleException;
use App\Models\Evaluation;
use App\Models\EvaluationCriteria;
use App\Models\Student;
use App\Models\User;
use App\Policies\EvaluationPolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Supervisor evaluation write logic shared by the website and the mobile
 * API: create / update a draft (responses sync + overall rating), submit,
 * and the admin-only lock / reopen.
 *
 * Authorization is the caller's job (EvaluationPolicy,
 * StudentPolicy::evaluate). The state rules ("only drafts can be edited",
 * ...) are checked there too, and re-checked here under a row lock so two
 * requests racing on the same evaluation can't both win: the loser gets an
 * EvaluationRuleException with the policy's wording.
 */
class EvaluationService
{
    public const RATING_MIN = 1;

    public const RATING_MAX = 5;

    /** Period dates are bounded so an absurd year can't reach MySQL (a 500). */
    public const EARLIEST_DATE = '2000-01-01';

    public const LATEST_DATE = '2099-12-31';

    public const MAX_TEXT_LENGTH = 5000;

    public const MAX_COMMENT_LENGTH = 2000;

    /** More ratings than any real criteria list; caps the per-item exists queries. */
    public const MAX_RESPONSES = 200;

    public const UNRATED_MESSAGE = 'Every evaluation criterion must be rated before submitting.';

    /** Free-text fields copied straight from the validated input. */
    private const TEXT_FIELDS = ['strengths', 'areas_for_improvement', 'recommendations', 'supervisor_remarks'];

    /**
     * @param  bool  $forStudentSwitch  include student_id (creating a draft)
     * @param  bool  $activeCriteriaOnly  refuse ratings for deactivated criteria
     *                                    (the API; the website's form only ever
     *                                    shows active ones)
     */
    public static function rules(bool $forStudentSwitch = true, bool $activeCriteriaOnly = false): array
    {
        $criterionExists = Rule::exists('evaluation_criteria', 'id');

        if ($activeCriteriaOnly) {
            $criterionExists->where('is_active', true);
        }

        $text = ['nullable', 'string', 'max:'.self::MAX_TEXT_LENGTH];

        $rules = [
            'evaluation_period_start' => [
                'bail', 'required', 'date_format:Y-m-d',
                'after_or_equal:'.self::EARLIEST_DATE, 'before_or_equal:'.self::LATEST_DATE,
            ],
            'evaluation_period_end' => [
                'bail', 'required', 'date_format:Y-m-d',
                // Compared only against a string start: the date comparison
                // throws a TypeError (500) on an array.
                Rule::when(fn ($input) => is_string($input->evaluation_period_start), ['after_or_equal:evaluation_period_start']),
                'before_or_equal:'.self::LATEST_DATE,
            ],
            'strengths' => $text,
            'areas_for_improvement' => $text,
            'recommendations' => $text,
            'supervisor_remarks' => $text,
            'responses' => ['nullable', 'array', 'max:'.self::MAX_RESPONSES],
            'responses.*' => ['array'],
            'responses.*.evaluation_criteria_id' => ['bail', 'required', 'integer', 'numeric', $criterionExists, 'distinct'],
            'responses.*.rating' => ['bail', 'required', 'integer', 'numeric', 'min:'.self::RATING_MIN, 'max:'.self::RATING_MAX],
            'responses.*.comment' => ['nullable', 'string', 'max:'.self::MAX_COMMENT_LENGTH],
        ];

        if ($forStudentSwitch) {
            $rules['student_id'] = ['bail', 'required', 'integer', 'numeric', 'exists:students,id'];
        }

        return $rules;
    }

    /**
     * Active criteria in display order (category, then sort order).
     *
     * @return Collection<int, EvaluationCriteria>
     */
    public function activeCriteria(): Collection
    {
        return EvaluationCriteria::query()
            ->where('is_active', true)
            ->orderBy('category')
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * The evaluations a student sees on My Feedback (website and mobile
     * API): their own, submitted or locked only, never drafts.
     */
    public function studentFeedback(Student $student): HasMany
    {
        return $student->evaluations()
            ->whereIn('status', EvaluationPolicy::STUDENT_VISIBLE_STATUSES);
    }

    /**
     * Company and supervisor are snapshotted from the student's current
     * assignment.
     *
     * @param  array  $data  validated rules() data
     */
    public function createDraft(Student $student, array $data): Evaluation
    {
        return DB::transaction(function () use ($student, $data) {
            $evaluation = Evaluation::create([
                'student_id' => $student->id,
                'company_id' => $student->company_id,
                'supervisor_id' => $student->supervisor_id,
                'evaluation_period_start' => $data['evaluation_period_start'],
                'evaluation_period_end' => $data['evaluation_period_end'],
                ...$this->textFields($data),
                'status' => 'draft',
            ]);

            $this->syncResponses($evaluation, $data['responses'] ?? []);
            $this->refreshOverallRating($evaluation);

            return $evaluation;
        });
    }

    /**
     * @param  array  $data  validated rules(forStudentSwitch: false) data
     *
     * @throws EvaluationRuleException when it is no longer a draft
     */
    public function updateDraft(Evaluation $evaluation, array $data): Evaluation
    {
        return $this->underLock($evaluation, function (Evaluation $current) use ($data) {
            if ($current->status !== 'draft') {
                throw new EvaluationRuleException(EvaluationPolicy::EDIT_MESSAGE);
            }

            $current->update([
                'evaluation_period_start' => $data['evaluation_period_start'],
                'evaluation_period_end' => $data['evaluation_period_end'],
                ...$this->textFields($data),
            ]);

            $this->syncResponses($current, $data['responses'] ?? []);
            $this->refreshOverallRating($current);
        });
    }

    /**
     * @throws EvaluationRuleException when it is no longer a draft
     * @throws ValidationException when an active criterion has no rating
     */
    public function submit(Evaluation $evaluation): Evaluation
    {
        return $this->underLock($evaluation, function (Evaluation $current) {
            if ($current->status !== 'draft') {
                throw new EvaluationRuleException(EvaluationPolicy::SUBMIT_MESSAGE);
            }

            $activeCriteriaIds = EvaluationCriteria::query()->where('is_active', true)->pluck('id');
            $respondedCriteriaIds = $current->responses()->pluck('evaluation_criteria_id');

            if ($activeCriteriaIds->diff($respondedCriteriaIds)->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'responses' => self::UNRATED_MESSAGE,
                ]);
            }

            $this->refreshOverallRating($current);

            $current->update([
                'status' => 'submitted',
                'submitted_at' => now(),
            ]);
        });
    }

    /**
     * @throws EvaluationRuleException when it is not submitted
     */
    public function lock(Evaluation $evaluation, User $admin): Evaluation
    {
        return $this->underLock($evaluation, function (Evaluation $current) use ($admin) {
            if ($current->status !== 'submitted') {
                throw new EvaluationRuleException(EvaluationPolicy::LOCK_MESSAGE);
            }

            $current->update([
                'status' => 'locked',
                'locked_at' => now(),
                'locked_by' => $admin->id,
            ]);
        });
    }

    /**
     * @throws EvaluationRuleException when it is not submitted or locked
     */
    public function reopen(Evaluation $evaluation): Evaluation
    {
        return $this->underLock($evaluation, function (Evaluation $current) {
            if (! in_array($current->status, EvaluationPolicy::REOPENABLE_STATUSES, true)) {
                throw new EvaluationRuleException(EvaluationPolicy::REOPEN_MESSAGE);
            }

            $current->update([
                'status' => 'draft',
                'submitted_at' => null,
                'locked_at' => null,
                'locked_by' => null,
            ]);
        });
    }

    /**
     * Run $change on a row-locked fresh copy inside a transaction, then copy
     * the stored state back onto $evaluation (loaded relations are dropped,
     * since responses / lockedBy may have changed).
     *
     * @param  callable(Evaluation): void  $change
     *
     * @throws ModelNotFoundException when the evaluation was deleted meanwhile
     */
    private function underLock(Evaluation $evaluation, callable $change): Evaluation
    {
        DB::transaction(function () use ($evaluation, $change) {
            $current = Evaluation::query()->lockForUpdate()->find($evaluation->id)
                ?? throw (new ModelNotFoundException)->setModel(Evaluation::class, [$evaluation->id]);

            $change($current);

            $evaluation->setRawAttributes($current->getAttributes(), true);
        });

        return $evaluation->setRelations([]);
    }

    private function textFields(array $data): array
    {
        $fields = [];

        foreach (self::TEXT_FIELDS as $field) {
            $fields[$field] = $data[$field] ?? null;
        }

        return $fields;
    }

    private function syncResponses(Evaluation $evaluation, array $responses): void
    {
        $evaluation->responses()->delete();

        foreach ($responses as $response) {
            $evaluation->responses()->create([
                'evaluation_criteria_id' => (int) $response['evaluation_criteria_id'],
                'rating' => (int) $response['rating'],
                'comment' => $response['comment'] ?? null,
            ]);
        }
    }

    private function refreshOverallRating(Evaluation $evaluation): void
    {
        $average = $evaluation->responses()->avg('rating');

        $evaluation->update([
            'overall_rating' => $average !== null ? round($average, 2) : null,
        ]);
    }
}

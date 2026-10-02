<?php

namespace App\Services;

use App\Models\Evaluation;
use App\Models\EvaluationCriteria;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Supervisor evaluation write logic shared by the website and the mobile
 * API: create / update a draft (responses sync + overall rating), submit,
 * and the admin-only lock / reopen.
 *
 * Authorization — including "only drafts can be edited" — is the caller's
 * job (EvaluationPolicy, StudentPolicy::evaluate).
 */
class EvaluationService
{
    /** Free-text fields copied straight from the validated input. */
    private const TEXT_FIELDS = ['strengths', 'areas_for_improvement', 'recommendations', 'supervisor_remarks'];

    /**
     * @param  bool  $forStudentSwitch  include student_id (creating a draft)
     */
    public static function rules(bool $forStudentSwitch = true): array
    {
        $rules = [
            'evaluation_period_start' => ['required', 'date'],
            'evaluation_period_end' => ['required', 'date', 'after_or_equal:evaluation_period_start'],
            'strengths' => ['nullable', 'string', 'max:5000'],
            'areas_for_improvement' => ['nullable', 'string', 'max:5000'],
            'recommendations' => ['nullable', 'string', 'max:5000'],
            'supervisor_remarks' => ['nullable', 'string', 'max:5000'],
            'responses' => ['nullable', 'array'],
            'responses.*.evaluation_criteria_id' => ['required', 'integer', 'exists:evaluation_criteria,id', 'distinct'],
            'responses.*.rating' => ['required', 'integer', 'min:1', 'max:5'],
            'responses.*.comment' => ['nullable', 'string', 'max:2000'],
        ];

        if ($forStudentSwitch) {
            $rules['student_id'] = ['required', 'exists:students,id'];
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
     */
    public function updateDraft(Evaluation $evaluation, array $data): Evaluation
    {
        DB::transaction(function () use ($evaluation, $data) {
            $evaluation->update([
                'evaluation_period_start' => $data['evaluation_period_start'],
                'evaluation_period_end' => $data['evaluation_period_end'],
                ...$this->textFields($data),
            ]);

            $this->syncResponses($evaluation, $data['responses'] ?? []);
            $this->refreshOverallRating($evaluation);
        });

        return $evaluation;
    }

    /**
     * @throws ValidationException when an active criterion has no rating
     */
    public function submit(Evaluation $evaluation): Evaluation
    {
        $activeCriteriaIds = EvaluationCriteria::query()->where('is_active', true)->pluck('id');
        $respondedCriteriaIds = $evaluation->responses()->pluck('evaluation_criteria_id');

        if ($activeCriteriaIds->diff($respondedCriteriaIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'responses' => 'Every evaluation criterion must be rated before submitting.',
            ]);
        }

        $this->refreshOverallRating($evaluation);

        $evaluation->update([
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        return $evaluation;
    }

    public function lock(Evaluation $evaluation, User $admin): Evaluation
    {
        $evaluation->update([
            'status' => 'locked',
            'locked_at' => now(),
            'locked_by' => $admin->id,
        ]);

        return $evaluation;
    }

    public function reopen(Evaluation $evaluation): Evaluation
    {
        $evaluation->update([
            'status' => 'draft',
            'submitted_at' => null,
            'locked_at' => null,
            'locked_by' => null,
        ]);

        return $evaluation;
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
                'evaluation_criteria_id' => $response['evaluation_criteria_id'],
                'rating' => $response['rating'],
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

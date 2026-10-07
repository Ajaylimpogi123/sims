<?php

namespace App\Http\Requests\Api\V1;

use App\Rules\NotBoolean;

/**
 * GET /evaluations: the website's Supervisor Evaluations list (newest period
 * first), optionally filtered, cursor-paginated over
 * (evaluation_period_start, id) descending, the same keys as GET /feedback.
 * Filters only narrow the list; the caller's scope is applied by the
 * controller regardless.
 */
class ListEvaluationsRequest extends ListFeedbackRequest
{
    public const STATUSES = ['draft', 'submitted', 'locked'];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'status' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', self::STATUSES)],
            'student_id' => ['sometimes', 'nullable', new NotBoolean, 'integer', 'min:1'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function status(): ?string
    {
        return $this->validated('status');
    }

    public function studentId(): ?int
    {
        $id = $this->validated('student_id');

        return $id === null ? null : (int) $id;
    }

    public function search(): ?string
    {
        $search = trim((string) $this->validated('search'));

        return $search === '' ? null : $search;
    }
}

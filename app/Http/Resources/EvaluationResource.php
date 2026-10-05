<?php

namespace App\Http\Resources;

use App\Models\EvaluationResponse;
use App\Services\EvaluationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * A supervisor evaluation as staff see it (Supervisor Evaluations). The list
 * sends the summary; `detail()` adds the free-text fields, the saved ratings
 * grouped by category (built like My Feedback, see FeedbackResource) and
 * the raw `responses` for prefilling the edit form.
 *
 * The `can_*` flags are EvaluationPolicy for the requesting user. Load
 * `student.user:id,name`, `company:id,company_name`, `supervisor:id,name`,
 * `lockedBy:id,name` (plus `responses.criteria` for the detail).
 *
 * @mixin \App\Models\Evaluation
 */
class EvaluationResource extends JsonResource
{
    private bool $detail = false;

    public function detail(): static
    {
        $this->detail = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $gate = Gate::forUser($request->user());
        $student = $this->student;

        $summary = [
            'id' => $this->id,
            'status' => $this->status,
            'period_start' => $this->evaluation_period_start?->format('Y-m-d'),
            'period_end' => $this->evaluation_period_end?->format('Y-m-d'),
            'overall_rating' => $this->overall_rating !== null ? (float) $this->overall_rating : null,
            'rating_max' => EvaluationService::RATING_MAX,
            'student' => $student ? [
                'id' => $student->id,
                'user_id' => $student->user_id,
                'name' => $student->user?->name,
                'student_number' => $student->student_number,
            ] : null,
            'company' => $this->company ? ['id' => $this->company->id, 'name' => $this->company->company_name] : null,
            'supervisor' => $this->supervisor ? ['id' => $this->supervisor->id, 'name' => $this->supervisor->name] : null,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'locked_at' => $this->locked_at?->toIso8601String(),
            'locked_by' => $this->lockedBy ? ['id' => $this->lockedBy->id, 'name' => $this->lockedBy->name] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'can_edit' => $gate->allows('update', $this->resource),
            'can_submit' => $gate->allows('submit', $this->resource),
            'can_lock' => $gate->allows('lock', $this->resource),
            'can_reopen' => $gate->allows('reopen', $this->resource),
        ];

        if (! $this->detail) {
            return $summary;
        }

        return [
            ...$summary,
            'strengths' => $this->strengths,
            'areas_for_improvement' => $this->areas_for_improvement,
            'recommendations' => $this->recommendations,
            'supervisor_remarks' => $this->supervisor_remarks,
            'categories' => FeedbackResource::groupResponses($this->responses, withActiveFlag: true),
            'responses' => $this->responses
                ->filter(fn (EvaluationResponse $response) => $response->evaluation_criteria_id !== null)
                ->map(fn (EvaluationResponse $response) => [
                    'evaluation_criteria_id' => $response->evaluation_criteria_id,
                    'rating' => $response->rating,
                    'comment' => $response->comment,
                ])
                ->values()
                ->all(),
        ];
    }
}

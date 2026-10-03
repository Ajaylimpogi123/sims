<?php

namespace App\Http\Resources;

use App\Models\EvaluationResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A submitted or locked evaluation as the student sees it on My Feedback.
 * The list sends the summary; `detail()` adds the free-text fields and the
 * saved ratings grouped by category, built exactly like the website's
 * MyFeedback page: responses in their stored order, grouped by category in
 * first-appearance order, criteria taken from the saved responses (so a
 * criterion deactivated later still shows) and responses whose criterion
 * no longer exists skipped.
 *
 * Load `company:id,company_name` and `supervisor:id,name` (plus
 * `responses.criteria` for the detail) to avoid a query per item.
 *
 * @mixin \App\Models\Evaluation
 */
class FeedbackResource extends JsonResource
{
    public const RATING_MAX = 5;

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
        $summary = [
            'id' => $this->id,
            'period_start' => $this->evaluation_period_start?->format('Y-m-d'),
            'period_end' => $this->evaluation_period_end?->format('Y-m-d'),
            'status' => $this->status,
            'overall_rating' => $this->overall_rating !== null ? (float) $this->overall_rating : null,
            'rating_max' => self::RATING_MAX,
            'supervisor' => $this->supervisor ? ['id' => $this->supervisor->id, 'name' => $this->supervisor->name] : null,
            'company' => $this->company ? ['id' => $this->company->id, 'name' => $this->company->company_name] : null,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'locked_at' => $this->locked_at?->toIso8601String(),
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
            'categories' => $this->categories(),
        ];
    }

    /**
     * @return list<array{category: string, criteria: list<array<string, mixed>>}>
     */
    private function categories(): array
    {
        $groups = [];

        $this->responses
            ->filter(fn (EvaluationResponse $response) => $response->criteria !== null)
            ->each(function (EvaluationResponse $response) use (&$groups) {
                $criterion = $response->criteria;

                $groups[$criterion->category][] = [
                    'id' => $criterion->id,
                    'label' => $criterion->label,
                    'description' => $criterion->description,
                    'rating' => $response->rating,
                    'max' => self::RATING_MAX,
                    'comment' => $response->comment,
                ];
            });

        return collect($groups)
            ->map(fn (array $criteria, string|int $category) => ['category' => (string) $category, 'criteria' => $criteria])
            ->values()
            ->all();
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An evaluation criterion on the Administrator's Evaluation Criteria screen
 * (load the `responses` count).
 *
 * @mixin \App\Models\EvaluationCriteria
 */
class EvaluationCriterionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'label' => $this->label,
            'description' => $this->description,
            'sort_order' => (int) $this->sort_order,
            'is_active' => (bool) $this->is_active,
            'responses_count' => (int) $this->responses_count,
        ];
    }
}

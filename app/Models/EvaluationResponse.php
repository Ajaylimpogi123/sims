<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EvaluationResponse extends Model
{
    use HasFactory;

    /** The criterion columns copied on submit, keyed by snapshot column. */
    public const SNAPSHOT = [
        'criterion_label' => 'label',
        'criterion_category' => 'category',
        'criterion_description' => 'description',
    ];

    protected $fillable = [
        'evaluation_id',
        'evaluation_criteria_id',
        'criterion_label',
        'criterion_category',
        'criterion_description',
        'rating',
        'comment',
    ];

    /**
     * Never serialized as-is: displayCriterion() folds them into the
     * `criteria` object, so the website props keep their shape.
     */
    protected $hidden = [
        'criterion_label',
        'criterion_category',
        'criterion_description',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
        ];
    }

    public function evaluation()
    {
        return $this->belongsTo(Evaluation::class);
    }

    public function criteria()
    {
        return $this->belongsTo(EvaluationCriteria::class, 'evaluation_criteria_id');
    }

    /**
     * The criterion as it should be shown for this response: the live one,
     * with the label / category / description snapshotted when the
     * evaluation was submitted laid over it (drafts have no snapshot, so
     * they follow renames). A copy, never saved; `is_active` and
     * `sort_order` stay live. Null when the criterion no longer exists.
     */
    public function displayCriterion(): ?EvaluationCriteria
    {
        $criterion = $this->criteria;

        if ($criterion === null || $this->criterion_label === null) {
            return $criterion;
        }

        // Eager loading hands the same instance to every response of that
        // criterion, so never modify it in place.
        $copy = clone $criterion;

        foreach (self::SNAPSHOT as $column => $attribute) {
            $copy->setAttribute($attribute, $this->getAttribute($column));
        }

        return $copy;
    }

    /**
     * Swap the loaded `criteria` relation for displayCriterion(), for
     * payloads that serialize the model (the website's Inertia props).
     */
    public function useDisplayCriterion(): static
    {
        return $this->setRelation('criteria', $this->displayCriterion());
    }
}

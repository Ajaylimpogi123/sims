<?php

namespace App\Services;

use App\Models\EvaluationCriteria;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Evaluation criteria management (Administrator), shared by the website
 * and the mobile API. Saved evaluations keep their ratings whatever happens
 * here: a criterion that already has responses is never deleted, only
 * deactivated.
 */
class EvaluationCriteriaService
{
    /** sort_order is a signed INT column. */
    public const MAX_SORT_ORDER = 2147483647;

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'bail', 'integer', 'numeric', 'min:0', 'max:'.self::MAX_SORT_ORDER],
        ];
    }

    /**
     * @param  array  $data  validated rules() data
     */
    public function create(array $data): EvaluationCriteria
    {
        return EvaluationCriteria::create($this->attributes($data) + ['is_active' => true]);
    }

    /**
     * @param  array  $data  validated rules() data
     */
    public function update(EvaluationCriteria $criterion, array $data): EvaluationCriteria
    {
        $criterion->update($this->attributes($data));

        return $criterion;
    }

    public function setActive(EvaluationCriteria $criterion, bool $active): EvaluationCriteria
    {
        $criterion->update(['is_active' => $active]);

        return $criterion;
    }

    /**
     * Delete a criterion that was never used; one with saved responses is
     * deactivated instead. The check runs with the criterion row locked, so
     * a rating being saved at the same moment either lands first (and the
     * criterion is deactivated) or waits for the delete.
     *
     * @return bool true when deleted, false when deactivated instead
     *
     * @throws ModelNotFoundException when it was deleted meanwhile
     */
    public function delete(EvaluationCriteria $criterion): bool
    {
        return DB::transaction(function () use ($criterion) {
            $current = EvaluationCriteria::query()->lockForUpdate()->find($criterion->id)
                ?? throw (new ModelNotFoundException)->setModel(EvaluationCriteria::class, [$criterion->id]);

            if ($current->responses()->exists()) {
                $current->update(['is_active' => false]);
                $criterion->setRawAttributes($current->getAttributes(), true);

                return false;
            }

            $current->delete();
            $criterion->exists = false;

            return true;
        });
    }

    /**
     * A cleared sort order (null / "") means 0: the column is NOT NULL.
     */
    private function attributes(array $data): array
    {
        if (array_key_exists('sort_order', $data)) {
            $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        }

        return $data;
    }
}

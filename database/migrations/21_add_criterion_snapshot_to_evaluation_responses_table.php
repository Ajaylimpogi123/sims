<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The criterion's label, category and description as they were when the
     * evaluation was submitted, so renaming a criterion later doesn't
     * rewrite past evaluations. Null on drafts (they show the live
     * criterion); set by EvaluationService::submit() and cleared on reopen.
     *
     * Already submitted / locked evaluations are backfilled with the
     * criteria's current wording, the best record there is.
     */
    public function up(): void
    {
        Schema::table('evaluation_responses', function (Blueprint $table) {
            $table->string('criterion_label')->nullable()->after('evaluation_criteria_id');
            $table->string('criterion_category')->nullable()->after('criterion_label');
            $table->text('criterion_description')->nullable()->after('criterion_category');
        });

        $this->backfill();
    }

    /**
     * Snapshot the current criteria wording onto the responses of every
     * submitted / locked evaluation that has none yet. Public so it can be
     * tested without re-running the schema change.
     */
    public function backfill(): void
    {
        DB::table('evaluation_responses')
            ->join('evaluations', 'evaluations.id', '=', 'evaluation_responses.evaluation_id')
            ->join('evaluation_criteria', 'evaluation_criteria.id', '=', 'evaluation_responses.evaluation_criteria_id')
            ->whereIn('evaluations.status', ['submitted', 'locked'])
            ->whereNull('evaluation_responses.criterion_label')
            ->select(
                'evaluation_responses.id',
                'evaluation_criteria.label',
                'evaluation_criteria.category',
                'evaluation_criteria.description',
            )
            // By id: the update takes rows out of the whereNull filter, which
            // would make offset paging skip some.
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('evaluation_responses')->where('id', $row->id)->update([
                        'criterion_label' => $row->label,
                        'criterion_category' => $row->category,
                        'criterion_description' => $row->description,
                    ]);
                }
            }, 'evaluation_responses.id', 'id');
    }

    public function down(): void
    {
        Schema::table('evaluation_responses', function (Blueprint $table) {
            $table->dropColumn(['criterion_label', 'criterion_category', 'criterion_description']);
        });
    }
};

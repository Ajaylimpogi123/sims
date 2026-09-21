<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_supervisors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
        });

        $this->backfillFromExistingAssignments();
    }

    public function down(): void
    {
        Schema::dropIfExists('company_supervisors');
    }

    /**
     * Populate the roster from every existing students.supervisor_id +
     * company_id pair currently in use, so tightening
     * InternshipAssignmentController's supervisor validation to
     * "must be on the company's roster" doesn't invalidate assignments
     * that were made before this table existed. Must run in the same
     * migration batch as this table's creation, not a later one.
     */
    private function backfillFromExistingAssignments(): void
    {
        $pairs = DB::table('students')
            ->whereNotNull('company_id')
            ->whereNotNull('supervisor_id')
            ->select('company_id', 'supervisor_id')
            ->distinct()
            ->get();

        $now = now();

        foreach ($pairs as $pair) {
            DB::table('company_supervisors')->insertOrIgnore([
                'company_id' => $pair->company_id,
                'user_id' => $pair->supervisor_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};

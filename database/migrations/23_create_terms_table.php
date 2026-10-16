<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner feature (2026-10-16): School Year / Semester terms.
 *
 * - terms: one row per (school year, semester); exactly one is current.
 * - students.term_id: a student row is one OJT enrollment in one term
 *   (attendance, reports and evaluations inherit it through student_id).
 *
 * The first term is derived from today's date (Aug-Dec: 1st semester of
 * SY Y-(Y+1); Jan-May: 2nd semester of SY (Y-1)-Y; Jun-Jul: summer of SY
 * (Y-1)-Y), marked current, and every existing student is backfilled
 * into it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terms', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('school_year_start');
            $table->string('semester', 10);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_current')->default(false)->index();
            $table->timestamps();

            $table->unique(['school_year_start', 'semester']);
        });

        $today = Carbon::today();
        $month = (int) $today->month;
        $year = (int) $today->year;

        [$schoolYearStart, $semester] = match (true) {
            $month >= 8 => [$year, 'first'],
            $month <= 5 => [$year - 1, 'second'],
            default => [$year - 1, 'summer'],
        };

        $now = now();

        $termId = DB::table('terms')->insertGetId([
            'school_year_start' => $schoolYearStart,
            'semester' => $semester,
            'starts_on' => null,
            'ends_on' => null,
            'is_current' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('term_id')->nullable()->after('user_id')->constrained('terms')->restrictOnDelete();
        });

        DB::table('students')->update(['term_id' => $termId]);

        Schema::table('students', function (Blueprint $table) {
            $table->unsignedBigInteger('term_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('term_id');
        });

        Schema::dropIfExists('terms');
    }
};

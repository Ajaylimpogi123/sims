<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internship_reports', function (Blueprint $table) {
            $table->unique(['student_id', 'period_start', 'type'], 'internship_reports_student_period_type_unique');
        });
    }

    public function down(): void
    {
        Schema::table('internship_reports', function (Blueprint $table) {
            $table->dropUnique('internship_reports_student_period_type_unique');
        });
    }
};

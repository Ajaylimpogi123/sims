<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes only — no new tables. Supports the Dashboard & Analytics
     * module's scoped KPI/chart aggregate queries (App\Services\DashboardAnalyticsService).
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->index('internship_status');
            $table->index(['supervisor_id', 'internship_status']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->index('status');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('status');
        });

        Schema::table('internship_reports', function (Blueprint $table) {
            $table->index('status');
            $table->index(['status', 'created_at']);
        });

        Schema::table('evaluations', function (Blueprint $table) {
            $table->index('status');
            $table->index(['status', 'created_at']);
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->index('time_in_status');
            $table->index('time_out_status');
            $table->index(['date', 'time_in_status']);
            $table->index(['date', 'time_out_status']);
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // The composite index below currently satisfies InnoDB's
            // "an index must exist on the FK column" requirement for
            // students.supervisor_id — restore a plain index on it first
            // so MySQL allows dropping the composite one.
            $table->index('supervisor_id');
            $table->dropIndex(['supervisor_id', 'internship_status']);
            $table->dropIndex(['internship_status']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('internship_reports', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['status', 'created_at']);
        });

        Schema::table('evaluations', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['status', 'created_at']);
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex(['time_in_status']);
            $table->dropIndex(['time_out_status']);
            $table->dropIndex(['date', 'time_in_status']);
            $table->dropIndex(['date', 'time_out_status']);
        });
    }
};

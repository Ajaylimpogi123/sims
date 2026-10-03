<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether the device reported the leg's GPS fix as mocked (fake-GPS
     * app). Only the mobile app can detect it; null = unknown (website
     * submissions, staff-entered rows, rows from before this column).
     * Submissions are never refused for it: reviewers see a warning.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->boolean('time_in_mocked')->nullable()->after('time_in_accuracy');
            $table->boolean('time_out_mocked')->nullable()->after('time_out_accuracy');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['time_in_mocked', 'time_out_mocked']);
        });
    }
};

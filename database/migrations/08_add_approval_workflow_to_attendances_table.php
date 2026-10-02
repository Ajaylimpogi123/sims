<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('time_in_status')->nullable()->after('time_in'); // pending | approved | rejected
            $table->text('time_in_rejection_reason')->nullable()->after('time_in_status');
            $table->string('time_out_status')->nullable()->after('time_out'); // pending | approved | rejected
            $table->text('time_out_rejection_reason')->nullable()->after('time_out_status');
            $table->boolean('is_emergency')->default(false)->after('time_out_rejection_reason');
            $table->text('note')->nullable()->after('is_emergency');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn([
                'time_in_status',
                'time_in_rejection_reason',
                'time_out_status',
                'time_out_rejection_reason',
                'is_emergency',
                'note',
            ]);
        });
    }
};

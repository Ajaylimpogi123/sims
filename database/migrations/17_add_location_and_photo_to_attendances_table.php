<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Evidence captured by the student on each self-service attendance leg:
     * a live camera photo (stored on the private "local" disk) plus the
     * device's GPS coordinates. All nullable — staff-entered rows and rows
     * created before this feature have none.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->decimal('time_in_latitude', 10, 7)->nullable()->after('time_in');
            $table->decimal('time_in_longitude', 10, 7)->nullable()->after('time_in_latitude');
            $table->decimal('time_in_accuracy', 8, 2)->nullable()->after('time_in_longitude');
            $table->string('time_in_photo_path')->nullable()->after('time_in_accuracy');

            $table->decimal('time_out_latitude', 10, 7)->nullable()->after('time_out');
            $table->decimal('time_out_longitude', 10, 7)->nullable()->after('time_out_latitude');
            $table->decimal('time_out_accuracy', 8, 2)->nullable()->after('time_out_longitude');
            $table->string('time_out_photo_path')->nullable()->after('time_out_accuracy');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn([
                'time_in_latitude',
                'time_in_longitude',
                'time_in_accuracy',
                'time_in_photo_path',
                'time_out_latitude',
                'time_out_longitude',
                'time_out_accuracy',
                'time_out_photo_path',
            ]);
        });
    }
};

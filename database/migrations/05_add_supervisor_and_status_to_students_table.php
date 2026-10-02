<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('supervisor_id')->nullable()->after('company_id')->constrained('users')->nullOnDelete();
            $table->string('internship_status')->default('not_started')->after('supervisor_id'); // not_started | ongoing | completed
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supervisor_id');
            $table->dropColumn('internship_status');
        });
    }
};

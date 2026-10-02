<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ledger of one-off data repair commands that must never run twice
     * against the same database (e.g. app:shift-utc-to-manila). The unique
     * name doubles as a guard against two runs racing each other.
     */
    public function up(): void
    {
        Schema::create('data_fixes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->json('summary')->nullable();
            $table->timestamp('ran_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_fixes');
    }
};

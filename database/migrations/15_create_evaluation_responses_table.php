<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_id')->constrained()->cascadeOnDelete();
            // nullOnDelete rather than cascade/restrict: criteria should be
            // deactivated (is_active = false) instead of deleted, but if one
            // is ever removed we still don't want to destroy historical
            // evaluation responses.
            $table->foreignId('evaluation_criteria_id')->nullable()->constrained('evaluation_criteria')->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['evaluation_id', 'evaluation_criteria_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_responses');
    }
};

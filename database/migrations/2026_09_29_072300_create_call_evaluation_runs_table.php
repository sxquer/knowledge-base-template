<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('call_evaluation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('call_recording_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trigger', 16);
            $table->string('status', 32)->default('queued');
            $table->json('rubric_snapshot');
            $table->json('jev_answers')->nullable();
            $table->json('result')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['call_recording_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_evaluation_runs');
    }
};

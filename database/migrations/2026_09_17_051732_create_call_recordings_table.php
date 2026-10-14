<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_recordings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->foreignUuid('asset_id')->nullable()->constrained('assets');
            $table->string('title', 180);
            $table->text('source_url');
            $table->string('source_host');
            $table->string('status')->default('queued')->index();
            $table->text('transcript')->nullable();
            $table->json('words')->nullable();
            $table->json('segments')->nullable();
            $table->decimal('duration', 12, 3)->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedInteger('completion_revision')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_recordings');
    }
};

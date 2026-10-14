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
        Schema::table('call_recordings', function (Blueprint $table) {
            $table->string('transcription_provider', 30)->nullable();
            $table->string('transcription_model', 100)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('call_recordings', function (Blueprint $table) {
            $table->dropColumn(['transcription_provider', 'transcription_model']);
        });
    }
};

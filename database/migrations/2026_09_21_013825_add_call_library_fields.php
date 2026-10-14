<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', fn (Blueprint $t) => $t->uuid('page_id')->nullable()->change());
        Schema::table('call_recordings', function (Blueprint $t) {
            $t->uuid('page_id')->nullable()->change();
            $t->string('external_id')->nullable()->unique();
            $t->unsignedInteger('talk_seconds')->nullable();
            $t->timestamp('called_at')->nullable()->index();
            $t->string('direction', 40)->nullable();
            $t->string('evaluation_status')->default('pending')->index();
            $t->string('evaluation_error', 500)->nullable();
            $t->unsignedTinyInteger('score')->nullable();
            $t->json('evaluation')->nullable();
            $t->json('rubric_snapshot')->nullable();
            $t->timestamp('evaluated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('call_recordings', function (Blueprint $t) {
            $t->dropUnique(['external_id']);
            $t->dropColumn(['external_id', 'talk_seconds', 'called_at', 'direction', 'evaluation_status', 'evaluation_error', 'score', 'evaluation', 'rubric_snapshot', 'evaluated_at']);
        });
        // Nullable ownership is retained on rollback to preserve standalone recordings.
    }
};

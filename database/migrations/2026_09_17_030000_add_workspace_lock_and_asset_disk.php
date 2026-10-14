<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_locks', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
        });
        DB::table('workspace_locks')->insert(['id' => 1]);
        Schema::table('assets', fn (Blueprint $t) => $t->string('disk')->default('local'));
    }

    public function down(): void
    {
        Schema::table('assets', fn (Blueprint $t) => $t->dropColumn('disk'));
        Schema::dropIfExists('workspace_locks');
    }
};

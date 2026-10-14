<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_view_calls')->default(false);
            $table->boolean('can_listen_calls')->default(false);
        });
        DB::table('users')->update(['can_view_calls' => true, 'can_listen_calls' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['can_view_calls', 'can_listen_calls']);
        });
    }
};

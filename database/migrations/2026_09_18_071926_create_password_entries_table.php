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
        Schema::create('password_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title', 180);
            $table->text('username')->nullable();
            $table->text('secret');
            $table->text('url')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('password_entry_user', function (Blueprint $table) {
            $table->uuid('password_entry_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['password_entry_id', 'user_id']);
            $table->foreign('password_entry_id')->references('id')->on('password_entries')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('password_entry_user');
        Schema::dropIfExists('password_entries');
    }
};

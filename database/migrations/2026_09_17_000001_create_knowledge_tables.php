<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->string('role')->default('reader'));
        Schema::create('pages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('parent_id')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->string('title');
            $t->string('subtitle')->default('');
            $t->string('kind')->default('article');
            $t->string('status')->default('draft');
            $t->string('visibility')->default('team');
            $t->string('owner_label')->default('Команда МаркетингБро');
            $t->string('area')->default('');
            $t->json('document');
            $t->json('sources')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->string('source_key')->nullable()->unique();
            $t->string('source_hash')->nullable();
            $t->unsignedInteger('position')->default(0);
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
        });
        Schema::table('pages', function (Blueprint $t) {
            $t->foreign('parent_id')->references('id')->on('pages')->restrictOnDelete();
        });
        Schema::create('page_user', function (Blueprint $t) {
            $t->foreignUuid('page_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->unique(['page_id', 'user_id']);
        });
        Schema::create('revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('page_id')->constrained();
            $t->unsignedInteger('number');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('action');
            $t->string('reason');
            $t->json('snapshot');
            $t->timestamp('created_at');
            $t->unique(['page_id', 'number']);
        });
        Schema::create('publications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('page_id')->unique()->constrained();
            $t->foreignId('revision_id')->constrained();
            $t->boolean('indexed')->default(false);
            $t->timestamps();
        });
        Schema::create('operations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->constrained('users');
            $t->string('request_id', 100);
            $t->string('fingerprint', 64);
            $t->json('result');
            $t->timestamps();
            $t->unique(['actor_id', 'request_id']);
        });
        Schema::create('widget_definitions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('key')->unique();
            $t->string('name');
            $t->string('description')->default('');
            $t->string('view')->default('fields');
            $t->json('fields');
            $t->unsignedInteger('schema_version')->default(1);
            $t->timestamps();
        });
        Schema::create('assets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('page_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('path');
            $t->string('name');
            $t->string('mime');
            $t->unsignedBigInteger('size');
            $t->timestamps();
        });
        Schema::create('chunks', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('page_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('revision');
            $t->string('block_id');
            $t->text('text');
            $t->string('model')->nullable();
            $t->json('embedding')->nullable();
            $t->index(['page_id', 'revision']);
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value');
            $t->timestamps();
        });
        Schema::create('audits', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained();
            $t->string('topic');
            $t->string('status')->default('queued');
            $t->json('report')->nullable();
            $t->string('error')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['audits', 'settings', 'chunks', 'assets', 'widget_definitions', 'operations', 'publications', 'revisions', 'page_user', 'pages'] as $name) {
            Schema::dropIfExists($name);
        } Schema::table('users', fn (Blueprint $t) => $t->dropColumn('role'));
    }
};

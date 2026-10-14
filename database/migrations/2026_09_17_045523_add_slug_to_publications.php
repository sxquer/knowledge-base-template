<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publications', function (Blueprint $table) {
            $table->string('slug', 180)->nullable()->unique();
        });
        foreach (DB::table('publications')->orderBy('id')->get() as $publication) {
            $title = DB::table('pages')->where('id', $publication->page_id)->value('title');
            $base = substr(Str::slug($title ?? '', '-', 'ru'), 0, 160) ?: 'stranitsa';
            $slug = $base;
            $suffix = 2;
            while (DB::table('publications')->where('slug', $slug)->exists()) {
                $slug = $base.'-'.$suffix++;
            }
            DB::table('publications')->where('id', $publication->id)->update(['slug' => $slug]);
        }
    }

    public function down(): void
    {
        Schema::table('publications', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};

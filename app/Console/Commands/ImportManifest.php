<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Models\User;
use App\Services\Knowledge;
use App\Services\MarkdownImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ImportManifest extends Command
{
    protected $signature = 'kb:import {manifest : Path to a JSON manifest} {--user= : Administrator id}';

    protected $description = 'Import an exported Drive/local manifest without duplicates; changed existing sources are flagged for review';

    public function handle(Knowledge $kb, MarkdownImport $markdown): int
    {
        $user = $this->option('user') ? User::find($this->option('user')) : User::where('role', 'admin')->first();
        if (! $user || $user->role !== 'admin') {
            $this->error('An administrator is required.');

            return self::FAILURE;
        }
        $path = $this->argument('manifest');
        if (! is_file($path)) {
            $this->error('Manifest not found');

            return self::FAILURE;
        }
        $rows = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        Validator::make(['pages' => $rows], ['pages' => 'required|array|max:500', 'pages.*.source_key' => 'required|string|distinct|max:220', 'pages.*.title' => 'required|string|max:220', 'pages.*.markdown' => 'required|string', 'pages.*.parent_source_key' => 'nullable|string', 'pages.*.source_url' => 'nullable|url:http,https'])->validate();
        $created = 0;
        $skipped = 0;
        $conflicts = [];
        DB::transaction(function () use ($rows, $kb, $markdown, $user, &$created, &$skipped, &$conflicts) {
            $pending = $rows;
            while ($pending) {
                $next = [];
                $progress = false;
                foreach ($pending as $r) {
                    $hash = hash('sha256', json_encode($r, JSON_UNESCAPED_UNICODE));
                    $existing = Page::where('source_key', $r['source_key'])->first();
                    if ($existing) {
                        if ($existing->source_hash !== $hash) {
                            $conflicts[] = $r['source_key'];
                        } else {
                            $skipped++;
                        }$progress = true;

                        continue;
                    }
                    $parent = empty($r['parent_source_key']) ? null : Page::where('source_key', $r['parent_source_key'])->value('id');
                    if (! empty($r['parent_source_key']) && ! $parent) {
                        $next[] = $r;

                        continue;
                    }
                    $page = $kb->save($user, ['title' => $r['title'], 'kind' => $r['kind'] ?? 'article', 'parent_id' => $parent, 'document' => $markdown->document($r['markdown']), 'status' => 'review', 'owner_label' => $r['owner_label'] ?? 'Команда МаркетингБро', 'sources' => [['title' => $r['source_title'] ?? $r['title'], 'url' => $r['source_url'] ?? null, 'checked_at' => now()->toDateString(), 'author' => $r['author'] ?? null, 'published_at' => $r['published_at'] ?? null]], 'reason' => 'Импорт из первоисточника']);
                    $page->update(['source_key' => $r['source_key'], 'source_hash' => $hash]);
                    $created++;
                    $progress = true;
                }
                if (! $progress) {
                    throw new \RuntimeException('Missing parent or cyclic manifest: '.implode(',', array_column($next, 'source_key')));
                }
                $pending = $next;
            }
        });
        $this->info("Created: $created; skipped unchanged: $skipped; sources needing review: ".count($conflicts));
        foreach ($conflicts as $key) {
            $this->warn('Source changed; current knowledge preserved: '.$key);
        }

        return $conflicts ? self::FAILURE : self::SUCCESS;
    }
}

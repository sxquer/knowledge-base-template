<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Page;
use App\Models\User;
use App\Services\Knowledge;
use App\Services\MarkdownImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class ImportDrive extends Command
{
    protected $signature = 'kb:import-drive {manifest} {--user=}';

    protected $description = 'Import private Drive exports, attachments and internal links without replacing existing knowledge';

    public function handle(Knowledge $kb, MarkdownImport $markdown): int
    {
        $user = $this->option('user') ? User::findOrFail($this->option('user')) : User::where('role', 'admin')->firstOrFail();
        abort_unless($user->role === 'admin', 403);
        $base = realpath(dirname($this->argument('manifest')));
        $rows = json_decode(file_get_contents($this->argument('manifest')), true, 512, JSON_THROW_ON_ERROR);
        Validator::make(['rows' => $rows], [
            'rows' => 'required|array|max:500', 'rows.*.id' => 'required|string|distinct|max:180',
            'rows.*.title' => 'required|string|max:220', 'rows.*.parent' => 'nullable|string',
            'rows.*.source_url' => 'required|url:http,https', 'rows.*.assets' => 'sometimes|array',
            'rows.*.assets.*.path' => 'required|string', 'rows.*.assets.*.sha256' => 'required|string|size:64',
            'rows.*.assets.*.name' => 'required|string|max:255', 'rows.*.assets.*.mime' => 'required|string|max:100',
            'rows.*.assets.*.kind' => 'required|in:file,image,audio,video',
        ])->validate();
        foreach ($rows as $row) {
            foreach ($row['assets'] ?? [] as $file) {
                $path = realpath($base.'/'.$file['path']);
                if (! $path || ! str_starts_with($path, $base.DIRECTORY_SEPARATOR) || ! is_file($path) || hash_file('sha256', $path) !== $file['sha256']) {
                    throw new RuntimeException('Attachment missing or checksum mismatch: '.$row['id']);
                }
            }
        }
        $result = DB::transaction(function () use ($rows, $base, $user, $kb, $markdown): array {
            DB::table('workspace_locks')->where('id', 1)->lockForUpdate()->first();
            $pages = $fresh = $reused = [];
            $created = $skipped = $assets = 0;
            $pending = $rows;
            while ($pending) {
                $next = [];
                foreach ($pending as $row) {
                    if (! empty($row['parent']) && ! isset($pages[$row['parent']])) {
                        $next[] = $row;

                        continue;
                    }
                    $hash = hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE));
                    $page = Page::where('source_key', 'drive:'.$row['id'])->first();
                    $adopted = false;
                    if (! $page && ! empty($row['reuse_source_key'])) {
                        $page = Page::where('source_key', $row['reuse_source_key'])->first();
                        $adopted = (bool) $page;
                    }
                    if ($page) {
                        if (! $adopted && $page->source_hash !== $hash) {
                            throw new RuntimeException('Source changed; manual merge required: '.$row['id']);
                        }
                        if ($adopted && ! collect($page->sources)->contains('url', $row['source_url'])) {
                            $sources = [...($page->sources ?? []), $this->source($row)];
                            $page = $kb->save($user, [...$page->toArray(), 'sources' => $sources, 'parent_id' => isset($pages[$row['parent'] ?? '']) ? $pages[$row['parent']]->id : null, 'expected_revision' => $page->revision, 'reason' => 'Привязка существующего материала к Google Drive; содержимое сохранено'], $page);
                            $reused[] = $row['id'];
                            if (! empty($row['assets'])) {
                                $fresh[$row['id']] = true;
                            }
                        }
                        $skipped++;
                    } else {
                        $doc = isset($row['markdown']) ? $markdown->document($row['markdown']) : $row['document'];
                        $page = $kb->save($user, ['title' => $row['title'], 'kind' => $row['kind'] ?? 'article', 'parent_id' => isset($pages[$row['parent'] ?? '']) ? $pages[$row['parent']]->id : null, 'document' => $doc, 'status' => 'review', 'visibility' => 'team', 'sources' => [$this->source($row)], 'reason' => 'Импорт из Google Drive']);
                        $page->update(['source_key' => 'drive:'.$row['id'], 'source_hash' => $hash]);
                        $fresh[$row['id']] = true;
                        $created++;
                    }
                    $pages[$row['id']] = $page;
                }
                if (count($next) === count($pending)) {
                    throw new RuntimeException('Missing parent or cyclic hierarchy.');
                }
                $pending = $next;
            }
            foreach ($rows as $row) {
                if (! isset($fresh[$row['id']])) {
                    continue;
                }
                $page = $pages[$row['id']];
                $doc = $page->document;
                foreach ($row['assets'] ?? [] as $file) {
                    $path = 'drive/'.$page->id.'/'.$file['sha256'];
                    $stream = fopen($base.'/'.$file['path'], 'rb');
                    try {
                        if (! Storage::disk('local')->put($path, $stream)) {
                            throw new RuntimeException('Unable to store attachment.');
                        }
                    } finally {
                        fclose($stream);
                    }
                    $asset = Asset::create(['page_id' => $page->id, 'user_id' => $user->id, 'disk' => 'local', 'path' => $path, 'name' => $file['name'], 'mime' => $file['mime'], 'size' => filesize($base.'/'.$file['path'])]);
                    $doc['content'][] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $file['name']]]];
                    $doc['content'][] = ['type' => 'media', 'attrs' => ['assetId' => $asset->id, 'kind' => $file['kind']]];
                    $assets++;
                }
                $doc = $this->links($doc, $pages);
                if ($doc !== $page->document) {
                    $pages[$row['id']] = $kb->save($user, [...$page->toArray(), 'document' => $doc, 'expected_revision' => $page->revision, 'reason' => 'Вложения и внутренние ссылки импортированных материалов'], $page);
                }
            }

            return ['created' => $created, 'existing_preserved' => $skipped, 'assets' => $assets, 'adopted' => $reused, 'pages' => array_map(fn (Page $page) => $page->id, $pages)];
        });
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    private function source(array $row): array
    {
        return ['title' => $row['title'], 'url' => $row['source_url'], 'checked_at' => now()->toDateString(), 'published_at' => $row['created_at'] ?? null, 'author' => 'Маркетинг Бро · Google Drive'];
    }

    private function links(array $node, array $pages): array
    {
        foreach ($node['marks'] ?? [] as $index => $mark) {
            $url = $mark['attrs']['href'] ?? '';
            if (($mark['type'] ?? '') === 'link' && in_array(parse_url($url, PHP_URL_HOST), ['drive.google.com', 'docs.google.com'])) {
                preg_match('~/(?:d|folders)/([a-zA-Z0-9_-]+)~', $url, $match);
                parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
                $id = $match[1] ?? $query['id'] ?? '';
                if (isset($pages[$id])) {
                    $node['marks'][$index]['attrs']['href'] = '/app/'.$pages[$id]->id;
                }
            }
        }
        if (isset($node['content'])) {
            $node['content'] = array_map(fn (array $child) => $this->links($child, $pages), $node['content']);
        }

        return $node;
    }
}

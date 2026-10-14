<?php

namespace App\Services;

use App\Models\Chunk;
use App\Models\Page;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Search
{
    public function __construct(public Access $access, public OpenRouter $ai) {}

    public function run(User $user, string $query, bool $semantic = true): array
    {
        $pages = $this->access->pages($user)->keyBy('id');
        $hiddenCallBlocks = [];
        if (! $user->canViewCalls()) {
            foreach (Page::whereIn('id', $pages->keys())->get(['id', 'document']) as $page) {
                foreach ($page->document['content'] ?? [] as $node) {
                    if (in_array($node['type'] ?? '', ['callRecording', 'callReference'], true)) {
                        $hiddenCallBlocks[$page->id][$node['attrs']['id'] ?? 'root'] = true;
                    }
                }
            }
        }
        $visibleChunk = fn (Chunk $chunk): bool => ! isset($hiddenCallBlocks[$chunk->page_id][$chunk->block_id]);
        $q = mb_strtolower(trim($query));
        if (! $q) {
            return ['results' => [], 'mode' => 'text'];
        }
        $tokens = preg_split('/\s+/u', $q);
        $scores = [];
        $snippets = [];
        $blocks = [];
        foreach ($pages as $p) {
            if (str_contains(mb_strtolower($p->title), $q)) {
                $scores[$p->id] = 10;
                $snippets[$p->id] = $p->subtitle;
            }
        }
        $postgres = DB::getDriverName() === 'pgsql';
        $chunks = $postgres
         ? Chunk::whereIn('page_id', $pages->keys())->whereRaw("search_document @@ websearch_to_tsquery('russian', ?)", [$query])->orderByRaw("ts_rank_cd(search_document, websearch_to_tsquery('russian', ?)) DESC", [$query])->limit(80)->get()->filter($visibleChunk)
         : Chunk::whereIn('page_id', $pages->keys())->get()->filter($visibleChunk);
        foreach ($chunks as $c) {
            $text = mb_strtolower($c->text);
            $s = $postgres ? 1 : 0;
            foreach ($tokens as $t) {
                if (mb_strlen($t) > 1 && str_contains($text, $t)) {
                    $s += 2;
                }
            }
            if ($s > ($scores[$c->page_id] ?? 0)) {
                $scores[$c->page_id] = $s;
                $snippets[$c->page_id] = mb_substr($c->text, 0, 320);
                $blocks[$c->page_id] = $c->block_id;
            }
        }
        $mode = 'text';
        $warning = null;
        if ($semantic && $this->ai->ready() && Chunk::whereIn('page_id', $pages->keys())->where('model', $this->ai->config()['embedding_model'])->whereNotNull('embedding')->exists()) {
            try {
                $model = $this->ai->config()['embedding_model'];
                $vector = Cache::remember('query-vector:'.hash('sha256', $user->id.'|'.$model.'|'.$q), 600, fn () => $this->ai->embeddings([$query])[0]);
                $semanticScores = [];
                if ($postgres) {
                    $dimensions = count($vector);
                    $literal = '['.implode(',', array_map('floatval', $vector)).']';
                    $expression = $dimensions === 1536 ? 'embedding_vector::vector(1536)' : 'embedding_vector';
                    $vectorChunks = Chunk::whereIn('page_id', $pages->keys())->where('model', $model)->where('embedding_dimensions', $dimensions)->whereNotNull('embedding_vector')->orderByRaw($expression.' <=> ?::vector', [$literal])->limit(80)->get()->filter($visibleChunk);
                } else {
                    $vectorChunks = $chunks;
                }
                foreach ($vectorChunks as $c) {
                    if (! $c->embedding || $c->model !== $model || count($vector) !== count($c->embedding)) {
                        continue;
                    }
                    $dot = 0;
                    $a = 0;
                    $b = 0;
                    foreach ($vector as $i => $v) {
                        $dot += $v * $c->embedding[$i];
                        $a += $v * $v;
                        $b += $c->embedding[$i] ** 2;
                    }
                    $sim = $dot / max(sqrt($a * $b), 1e-9);
                    if ($sim > ($semanticScores[$c->page_id] ?? 0)) {
                        $semanticScores[$c->page_id] = $sim;
                        if (! isset($snippets[$c->page_id])) {
                            $snippets[$c->page_id] = mb_substr($c->text, 0, 320);
                            $blocks[$c->page_id] = $c->block_id;
                        }
                    }
                }
                // Reciprocal rank fusion; keep lexical exact matches and semantic neighbors.
                arsort($scores);
                arsort($semanticScores);
                $combined = [];
                foreach ([array_keys($scores), array_slice(array_keys($semanticScores), 0, 30)] as $ranked) {
                    foreach ($ranked as $rank => $id) {
                        $combined[$id] = ($combined[$id] ?? 0) + 1 / (60 + $rank + 1);
                    }
                }
                $scores = $combined;
                $mode = 'hybrid';
            } catch (\Throwable $e) {
                $warning = 'Семантический поиск временно недоступен. Показаны текстовые совпадения.';
            }
        }
        arsort($scores);
        $results = [];
        foreach (array_slice(array_keys($scores), 0, 20) as $id) {
            $results[] = ['id' => $id, 'title' => $pages[$id]->title, 'status' => $pages[$id]->status, 'snippet' => $snippets[$id] ?? '', 'block_id' => $blocks[$id] ?? null];
        }

        return compact('results', 'mode', 'warning');
    }
}

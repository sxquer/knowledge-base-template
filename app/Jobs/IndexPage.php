<?php

namespace App\Jobs;

use App\Models\Chunk;
use App\Models\Page;
use App\Services\OpenRouter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class IndexPage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public string $pageId, public int $revision) {}

    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function handle(OpenRouter $ai): void
    {
        if (! $ai->ready()) {
            return;
        }
        $page = Page::find($this->pageId);
        if (! $page || $page->archived_at) {
            return;
        }
        // Publication revisions do not change the content index. Index current chunks on explicit reindex.
        $chunks = Chunk::where('page_id', $page->id)->get();
        foreach ($chunks->chunk(12) as $batch) {
            $vectors = $ai->embeddings($batch->pluck('text')->all());
            foreach ($batch->values() as $i => $chunk) {
                $values = ['embedding' => json_encode($vectors[$i]), 'model' => $ai->config()['embedding_model']];
                if (DB::getDriverName() === 'pgsql') {
                    $values['embedding_vector'] = '['.implode(',', array_map('floatval', $vectors[$i])).']';
                    $values['embedding_dimensions'] = count($vectors[$i]);
                }
                Chunk::whereKey($chunk->id)->where('revision', $chunk->revision)->update($values);
            }
        }
    }
}

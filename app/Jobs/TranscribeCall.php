<?php

namespace App\Jobs;

use App\Exceptions\CallProcessingException;
use App\Models\Asset;
use App\Models\CallRecording;
use App\Models\Page;
use App\Models\User;
use App\Services\Access;
use App\Services\CallAudio;
use App\Services\CallLibrary;
use App\Services\CallTranscription;
use App\Services\Knowledge;
use App\Services\OpenRouter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TranscribeCall implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 150;

    public function __construct(public string $recordingId)
    {
        $this->onConnection('calls')->onQueue('calls');
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(CallAudio $audio, CallTranscription $transcriber, OpenRouter $ai, Access $access, Knowledge $kb): void
    {
        $lock = Cache::lock('call:'.$this->recordingId, 180);
        if (! $lock->get()) {
            $this->release(30);

            return;
        }
        try {
            $call = CallRecording::find($this->recordingId);
            if (! $call || $call->status === 'ready') {
                return;
            }
            $page = Page::find($call->page_id);
            $user = User::find($call->user_id);
            if (! $user || ! $user->canEdit() || ($call->page_id && (! $page || $page->archived_at || ! $access->read($user, $page) || ! in_array($call->id, $kb->documents->recordingIds($page->document))))) {
                $call->update(['status' => 'cancelled', 'error' => 'Обработка остановлена: виджет удалён или изменились права доступа.']);

                return;
            }
            if (! $call->asset_id) {
                $call->update(['status' => 'downloading', 'error' => null]);
                $asset = $audio->acquire($call, $access);
                $call->update(['asset_id' => $asset->id]);
            }
            if (! $transcriber->ready($ai)) {
                $call->update(['status' => 'waiting_config', 'error' => null]);

                return;
            }
            $call->update(['status' => 'transcribing', 'error' => null]);
            $result = $transcriber->transcribe(Asset::findOrFail($call->asset_id), $ai);
            DB::transaction(function () use ($call, $result, $user, $kb): void {
                DB::table('workspace_locks')->where('id', 1)->lockForUpdate()->first();
                $page = $call->page_id ? Page::findOrFail($call->page_id) : null;
                $call->update([...$result, 'status' => 'ready', 'error' => null]);
                if ($page && ! $page->archived_at && in_array($call->id, $kb->documents->recordingIds($page->document))) {
                    $updated = $kb->save($user, [...$page->toArray(), 'expected_revision' => $page->revision, 'reason' => 'Готова расшифровка звонка «'.$call->title.'»'], $page);
                    $call->update(['completion_revision' => $updated->revision]);
                }
            });
            if (! $call->page_id) {
                app(CallLibrary::class)->queueEvaluation($call->fresh());
            }
        } catch (\Throwable $error) {
            $safe = $error::class === \RuntimeException::class || $error instanceof CallProcessingException
                ? mb_substr($error->getMessage(), 0, 480) : 'Не удалось обработать запись. Попробуйте повторить.';
            $retryable = ! ($error instanceof CallProcessingException) || $error->retryable;
            CallRecording::whereKey($this->recordingId)->update(['status' => $retryable && $this->attempts() < $this->tries ? 'retrying' : 'failed', 'error' => $safe]);
            if (! $retryable) {
                return;
            }
            // Never put signed source URLs or provider payloads in failed_jobs or logs.
            throw new \RuntimeException($safe);
        } finally {
            $lock->release();
        }
    }

    public function failed(?\Throwable $error): void
    {
        CallRecording::whereKey($this->recordingId)->where('status', '!=', 'ready')->update(['status' => 'failed', 'error' => 'Обработка не завершилась. Запись сохранена, можно повторить попытку.']);
    }
}

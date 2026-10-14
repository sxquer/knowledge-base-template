<?php

namespace App\Jobs;

use App\Models\CallEvaluationRun;
use App\Models\CallRecording;
use App\Models\User;
use App\Services\CallEvaluation;
use App\Services\CallLibrary;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class EvaluateCall implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public string $recordingId, public ?string $runId = null)
    {
        $this->onConnection('calls')->onQueue('calls');
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(CallLibrary $library, CallEvaluation $evaluation): void
    {
        $lock = Cache::lock('call:'.$this->recordingId, 180);
        if (! $lock->get()) {
            $this->release(30);

            return;
        }
        $run = null;
        try {
            $call = CallRecording::find($this->recordingId);
            if (! $call || $call->status !== 'ready' || $call->evaluation_status === 'ready') {
                return;
            }
            $run = $this->runId ? CallEvaluationRun::find($this->runId) : null;
            if (($run?->trigger ?? 'automatic') === 'automatic' && ! $library->config()['evaluation_enabled']) {
                $call->update(['evaluation_status' => 'paused', 'evaluation_error' => null]);
                $run?->update(['status' => 'paused', 'finished_at' => now()]);

                return;
            }
            $user = User::find($run?->requested_by ?? $call->user_id);
            if (! $user || ! $user->canEdit()) {
                $call->update(['evaluation_status' => 'failed', 'evaluation_error' => 'Автор обработки больше не имеет права редактирования.']);
                $run?->update(['status' => 'failed', 'error' => 'Автор обработки больше не имеет права редактирования.', 'finished_at' => now()]);

                return;
            }
            $library->requireRead($user, $call);
            if (! $evaluation->ready()) {
                $call->update(['evaluation_status' => 'waiting_config', 'evaluation_error' => null]);
                $run?->update(['status' => 'waiting_config', 'finished_at' => now()]);

                return;
            }
            $rubric = $run?->rubric_snapshot ?? $library->config()['rubric'];
            $run ??= CallEvaluationRun::create([
                'call_recording_id' => $call->id, 'requested_by' => $user->id,
                'trigger' => 'automatic', 'status' => 'queued', 'rubric_snapshot' => $rubric, 'jev_answers' => [],
            ]);
            $call->update(['evaluation_status' => 'evaluating', 'evaluation_error' => null]);
            $run->update(['status' => 'evaluating', 'started_at' => now(), 'error' => null]);
            $result = $evaluation->evaluate($call, $rubric, function (array $entries) use ($run): void {
                $run->update(['jev_answers' => [...($run->jev_answers ?? []), ...$entries]]);
            }, $run->jev_answers ?? []);
            DB::transaction(function () use ($call, $result, $rubric, $run): void {
                $fresh = CallRecording::whereKey($call->id)->lockForUpdate()->firstOrFail();
                if ($run->trigger === 'automatic' && ! app(CallLibrary::class)->config()['evaluation_enabled']) {
                    $fresh->update(['evaluation_status' => 'paused', 'evaluation_error' => null]);
                    $run->update(['status' => 'paused', 'finished_at' => now()]);

                    return;
                }
                if ($fresh->status !== 'ready' || hash('sha256', $fresh->transcript) !== $result['transcript_hash']) {
                    $fresh->update(['evaluation_status' => 'pending']);
                    $run->update(['status' => 'stale', 'finished_at' => now()]);

                    return;
                }
                $fresh->update(['evaluation' => $result, 'score' => $result['score'], 'rubric_snapshot' => $rubric,
                    'evaluation_status' => 'ready', 'evaluation_error' => null, 'evaluated_at' => now()]);
                $run->update(['status' => 'ready', 'result' => $result, 'score' => $result['score'], 'finished_at' => now()]);
            });
        } catch (\Throwable $e) {
            $safe = $e instanceof ConnectionException ? 'OpenRouter не ответил вовремя. Повторяем оценку.'
                : ($e::class === \RuntimeException::class ? mb_substr($e->getMessage(), 0, 480) : 'Не удалось оценить звонок. Повторяем попытку.');
            CallRecording::whereKey($this->recordingId)->update(['evaluation_status' => 'retrying', 'evaluation_error' => $safe]);
            $run?->update(['status' => 'retrying', 'error' => $safe]);
            throw new \RuntimeException($safe);
        } finally {
            $lock->release();
        }
    }

    public function failed(?\Throwable $e): void
    {
        $call = CallRecording::find($this->recordingId);
        if ($call && $call->evaluation_status !== 'ready') {
            $hasPreviousEvaluation = $call->evaluation !== null;
            $call->update(['evaluation_status' => $hasPreviousEvaluation ? 'ready' : 'failed',
                'evaluation_error' => $hasPreviousEvaluation ? 'Повторная оценка не завершилась. Показан предыдущий балл.' : 'Разбор не завершился. Можно повторить оценку.']);
        }
        if ($this->runId) {
            CallEvaluationRun::whereKey($this->runId)->where('status', '!=', 'ready')->update(['status' => 'failed', 'error' => 'Разбор не завершился. Можно повторить оценку.', 'finished_at' => now()]);
        }
    }
}

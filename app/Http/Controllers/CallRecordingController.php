<?php

namespace App\Http\Controllers;

use App\Jobs\TranscribeCall;
use App\Models\CallRecording;
use App\Models\Page;
use App\Services\Access;
use App\Services\CallAudio;
use App\Services\CallLibrary;
use App\Services\Knowledge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CallRecordingController extends Controller
{
    public function store(Request $request, Page $page, Access $access, Knowledge $kb, CallAudio $audio): array
    {
        abort_unless($request->user()->canViewCalls(), 403);
        $access->requireEdit($request->user(), $page);
        $data = $request->validate(['source_url' => 'required|string|max:4096', 'title' => 'required|string|max:180', 'expected_revision' => 'required|integer', 'request_id' => 'required|string|min:8|max:100']);
        try {
            $host = $audio->validateUrl($data['source_url']);
        } catch (\RuntimeException $error) {
            throw ValidationException::withMessages(['source_url' => $error->getMessage()]);
        }

        return $kb->operation($request->user(), $data['request_id'], ['page_id' => $page->id, ...$data], function () use ($request, $page, $data, $host, $kb): array {
            $page = $page->fresh();
            abort_if($page->archived_at, 422, 'Нельзя добавлять звонок в архив.');
            $call = CallRecording::create(['page_id' => $page->id, 'user_id' => $request->user()->id, 'title' => $data['title'], 'source_url' => $data['source_url'], 'source_host' => $host]);
            $doc = $page->document;
            $doc['content'][] = ['type' => 'callRecording', 'attrs' => ['recordingId' => $call->id]];
            $saved = $kb->save($request->user(), [...$page->toArray(), 'document' => $doc, 'expected_revision' => $data['expected_revision'], 'reason' => 'Добавлен звонок «'.$call->title.'»'], $page);
            TranscribeCall::dispatch($call->id)->afterCommit();

            return ['page' => $kb->present($saved), 'recording' => $call->fresh()->snapshot()];
        });
    }

    public function show(Request $request, CallRecording $recording, Access $access): array
    {
        app(CallLibrary::class)->requireRead($request->user(), $recording);

        return [...$recording->snapshot(),
            'evaluation_runs' => $recording->evaluationRuns()->orderByDesc('created_at')->get()->toArray(),
            'reviewed_at' => DB::table('call_reviews')->where('call_recording_id', $recording->id)->where('user_id', $request->user()->id)->value('reviewed_at')];
    }

    public function retry(Request $request, CallRecording $recording, Access $access): array
    {
        app(CallLibrary::class)->requireEdit($request->user(), $recording);
        $request->validate(['retranscribe' => 'sometimes|boolean']);

        return DB::transaction(function () use ($recording, $request): array {
            $recording = CallRecording::whereKey($recording->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($recording->status, ['failed', 'waiting_config', 'cancelled']) || ($recording->status === 'ready' && $request->boolean('retranscribe')), 409, 'Запись уже обрабатывается или расшифровка готова.');
            $recording->update(['status' => 'queued', 'error' => null, 'evaluation_status' => 'pending', 'evaluation' => null, 'score' => null, 'rubric_snapshot' => null, 'evaluated_at' => null]);
            TranscribeCall::dispatch($recording->id)->afterCommit();

            return $recording->snapshot();
        });
    }
}

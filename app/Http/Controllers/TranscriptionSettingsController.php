<?php

namespace App\Http\Controllers;

use App\Models\CallRecording;
use App\Models\Setting;
use App\Services\CallLibrary;
use App\Services\CallTranscription;
use App\Services\OpenRouter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TranscriptionSettingsController extends Controller
{
    public function update(Request $request, CallTranscription $transcriber, OpenRouter $ai): array
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate([
            'provider' => ['required', Rule::in(['openrouter', 'xai'])],
            'xai_key' => ['nullable', 'string', 'max:1000'],
            'xai_model' => ['required', Rule::in(['grok-voice-transcribe-1.0', CallTranscription::XAI_MODEL])],
        ]);

        return DB::transaction(function () use ($data, $transcriber, $ai): array {
            DB::table('workspace_locks')->where('id', 1)->lockForUpdate()->first();
            $settings = $transcriber->config();
            $key = trim($data['xai_key'] ?? '') ?: $settings['xai_key'];
            if ($data['provider'] === 'xai' && $key === '') {
                throw ValidationException::withMessages(['xai_key' => 'Добавьте API-ключ xAI.']);
            }
            Setting::updateOrCreate(['key' => 'transcription'], ['value' => ['provider' => $data['provider'], 'xai_key' => $key, 'xai_model' => $data['xai_model']]]);
            $transcriber->resumeWaiting($ai);
            foreach (CallRecording::where('evaluation_status', 'waiting_config')->get() as $call) {
                app(CallLibrary::class)->queueEvaluation($call);
            }

            return ['saved' => true, 'transcription' => $transcriber->publicConfig($ai)];
        });
    }
}

<?php

namespace App\Services;

use App\Exceptions\CallProcessingException;
use App\Jobs\TranscribeCall;
use App\Models\Asset;
use App\Models\CallRecording;
use App\Models\Setting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CallTranscription
{
    public const XAI_MODEL = 'grok-voice-transcribe-2.0';

    public function config(): array
    {
        return array_merge(['provider' => 'openrouter', 'xai_key' => '', 'xai_model' => self::XAI_MODEL], Setting::find('transcription')?->value ?? []);
    }

    public function ready(OpenRouter $ai): bool
    {
        $settings = $this->config();

        return $settings['provider'] === 'xai' ? ! empty($settings['xai_key']) : $ai->ready();
    }

    public function publicConfig(OpenRouter $ai): array
    {
        $settings = $this->config();

        return ['provider' => $settings['provider'], 'xai_configured' => ! empty($settings['xai_key']), 'xai_model' => $settings['xai_model'], 'diarization' => $settings['provider'] === 'xai', 'configured' => $this->ready($ai)];
    }

    public function resumeWaiting(OpenRouter $ai): void
    {
        if (! $this->ready($ai)) {
            return;
        }
        foreach (CallRecording::where('status', 'waiting_config')->get() as $call) {
            if (CallRecording::whereKey($call->id)->where('status', 'waiting_config')->update(['status' => 'queued', 'error' => null])) {
                TranscribeCall::dispatch($call->id)->afterCommit();
            }
        }
    }

    public function transcribe(Asset $asset, OpenRouter $ai): array
    {
        $format = ['audio/mpeg' => 'mp3', 'audio/wav' => 'wav', 'audio/flac' => 'flac', 'audio/ogg' => 'ogg', 'audio/mp4' => 'm4a', 'audio/webm' => 'webm', 'audio/aac' => 'aac'][$asset->mime] ?? null;
        if (! $format || $asset->size > CallAudio::MAX_BYTES) {
            throw new RuntimeException('Неподдерживаемый формат или размер аудио.');
        }
        $settings = $this->config();
        if (! $this->ready($ai)) {
            throw new CallProcessingException('Подключите выбранный сервис распознавания в настройках.', false);
        }
        $provider = $settings['provider'];
        try {
            if ($provider === 'xai') {
                $response = $this->xai($asset, $format, $settings);
            } else {
                $response = $ai->client(75)->post('https://openrouter.ai/api/v1/audio/transcriptions', [
                    'model' => 'openai/whisper-1',
                    'input_audio' => ['data' => base64_encode(Storage::disk($asset->disk)->get($asset->path)), 'format' => $format],
                    'response_format' => 'verbose_json', 'timestamp_granularities' => ['word', 'segment'],
                ]);
            }
        } catch (\Throwable) {
            throw new CallProcessingException('Сервис расшифровки не ответил вовремя. Запись сохранена; попробуйте ещё раз.');
        }
        if (! $response->successful()) {
            if ($provider === 'openrouter' && $response->status() === 403 && $response->json('error') === 'Access denied by security policy.') {
                throw new CallProcessingException('OpenRouter блокирует подключение с сервера. Требуется настройка доступа к провайдеру.', false);
            }
            throw new CallProcessingException(($provider === 'xai' ? 'xAI' : 'OpenRouter').': HTTP '.$response->status().'. Проверьте ключ и баланс или повторите позже.', $response->status() === 429 || $response->status() === 408 || $response->serverError());
        }
        $data = $response->json();
        if (! is_array($data) || ! is_string($data['text'] ?? null)) {
            throw new RuntimeException('Сервис вернул некорректную расшифровку.');
        }
        $wordItems = $data['words'] ?? [];
        if ($provider === 'xai' && is_array($wordItems)) {
            $wordItems = array_map(fn ($item) => is_array($item) ? [...$item, 'word' => $item['text'] ?? null] : $item, $wordItems);
        }
        $words = $this->timings($wordItems, 'word');
        $segments = $this->timings($data['segments'] ?? [], 'text');
        $duration = $data['duration'] ?? $data['usage']['seconds'] ?? null;

        return ['transcript' => mb_substr($data['text'], 0, 500000), 'words' => $words, 'segments' => $segments, 'duration' => is_numeric($duration) && is_finite((float) $duration) && $duration >= 0 ? (float) $duration : null, 'transcription_provider' => $provider, 'transcription_model' => $provider === 'xai' ? $settings['xai_model'] : 'openai/whisper-1'];
    }

    private function xai(Asset $asset, string $format, array $settings): Response
    {
        $stream = Storage::disk($asset->disk)->readStream($asset->path);
        if (! is_resource($stream)) {
            throw new RuntimeException('Аудиозапись недоступна.');
        }
        try {
            return Http::withToken($settings['xai_key'])->acceptJson()->connectTimeout(12)->timeout(75)
                ->withOptions(['proxy' => config('services.xai.proxy') ?: ''])
                ->attach('file', $stream, 'recording.'.$format, ['Content-Type' => $asset->mime])
                ->post('https://api.x.ai/v1/stt', ['model' => $settings['xai_model'], 'diarize' => 'true', 'language' => 'ru', 'format' => 'true']);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function timings(mixed $items, string $textKey): array
    {
        $clean = [];
        if (! is_array($items)) {
            return $clean;
        }
        foreach (array_slice($items, 0, 50000) as $item) {
            if (! is_array($item) || ! is_string($item[$textKey] ?? null) || ! is_numeric($item['start'] ?? null) || ! is_numeric($item['end'] ?? null) || ! is_finite((float) $item['start']) || ! is_finite((float) $item['end']) || $item['start'] < 0 || $item['end'] < $item['start']) {
                continue;
            }
            $timing = [$textKey => mb_substr($item[$textKey], 0, 20000), 'start' => (float) $item['start'], 'end' => (float) $item['end']];
            if (isset($item['speaker']) && is_int($item['speaker']) && $item['speaker'] >= 0 && $item['speaker'] <= 1000) {
                $timing['speaker'] = $item['speaker'];
            }
            $clean[] = $timing;
        }
        usort($clean, fn (array $a, array $b) => $a['start'] <=> $b['start']);

        return $clean;
    }
}

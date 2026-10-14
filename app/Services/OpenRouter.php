<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class OpenRouter
{
    public function config(): array
    {
        return array_replace(['key' => '', 'chat_model' => 'openai/gpt-4.1-mini', 'embedding_model' => 'openai/text-embedding-3-small'], Setting::find('openrouter')?->value ?? []);
    }

    public function ready(): bool
    {
        return ! empty($this->config()['key']);
    }

    public function client(int $timeout = 70): PendingRequest
    {
        $request = Http::withToken($this->config()['key'])->acceptJson()->connectTimeout(12)->timeout($timeout);
        $proxy = config('services.openrouter.proxy');

        return $proxy ? $request->withOptions(['proxy' => $proxy]) : $request;
    }

    private function request(string $path, array $data, int $timeout = 70): array
    {
        $c = $this->config();
        abort_unless(! empty($c['key']), 422, 'Добавьте ключ OpenRouter в настройках.');
        $response = $this->client($timeout)->post('https://openrouter.ai/api/v1/'.$path, [...$data, 'provider' => ['data_collection' => 'deny']]);
        if (! $response->successful()) {
            throw new \RuntimeException('OpenRouter: HTTP '.$response->status().'. Проверьте ключ, баланс и модель.');
        }

        return $response->json();
    }

    public function embeddings(array $texts): array
    {
        $r = $this->request('embeddings', ['model' => $this->config()['embedding_model'], 'input' => $texts]);
        $data = collect($r['data'] ?? [])->sortBy('index')->values()->pluck('embedding')->all();
        if (count($data) !== count($texts)) {
            throw new \RuntimeException('OpenRouter вернул неполные эмбеддинги.');
        }
        foreach ($data as $v) {
            if (! is_array($v) || count($v) < 1 || count(array_filter($v, 'is_numeric')) !== count($v)) {
                throw new \RuntimeException('Некорректный эмбеддинг.');
            }
        }

        return $data;
    }

    public function json(string $system, array $input, int $timeout = 70): array
    {
        $r = $this->request('chat/completions', ['model' => $this->config()['chat_model'], 'temperature' => 0, 'response_format' => ['type' => 'json_object'], 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => json_encode($input, JSON_UNESCAPED_UNICODE)]]], $timeout);
        $data = json_decode($r['choices'][0]['message']['content'] ?? '', true);
        if (! is_array($data)) {
            throw new \RuntimeException('Модель не вернула корректный JSON.');
        }

        return $data;
    }

    public function decisions(array $state, array $questions, string $model = 'typesafe/jev-1.13'): array
    {
        abort_unless($this->ready(), 422, 'Добавьте ключ OpenRouter в настройках.');
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = $this->client(25)->connectTimeout(8)->post('https://openrouter.ai/api/alpha/decisions', [
                    'model' => $model,
                    'state' => $state,
                    'questions' => $questions,
                    'provider' => ['data_collection' => 'deny'],
                ]);
            } catch (ConnectionException $e) {
                if ($attempt === 3) {
                    throw $e;
                }
                usleep(500_000);

                continue;
            }
            if ($response->successful() || ! in_array($response->status(), [429, 500, 502, 503, 504], true) || $attempt === 3) {
                break;
            }
            usleep(500_000);
        }
        if (! $response->successful()) {
            throw new \RuntimeException('OpenRouter JEV: HTTP '.$response->status().'. Проверьте ключ, баланс и доступность модели.');
        }
        $data = $response->json();
        if (! is_array($data) || ! is_array($data['answers'] ?? null)) {
            throw new \RuntimeException('JEV вернул неполный ответ. Повторите оценку.');
        }

        return $data;
    }
}

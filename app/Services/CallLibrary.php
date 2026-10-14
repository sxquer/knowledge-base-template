<?php

namespace App\Services;

use App\Jobs\EvaluateCall;
use App\Models\CallEvaluationRun;
use App\Models\CallRecording;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CallLibrary
{
    public function defaults(): array
    {
        return [
            'enabled' => false, 'evaluation_enabled' => false, 'account' => '', 'secret' => '', 'min_talk_seconds' => 40,
            'start_date' => now()->subDays(7)->toDateString(), 'timezone' => 'Europe/Moscow',
            'owner_id' => null,
            'rubric' => [
                'version' => 1, 'title' => 'Условный эталон первого разговора', 'provisional' => true,
                'instructions' => 'Цель: понять задачу клиента и согласовать следующий шаг. Оценивайте смысл, а не дословное совпадение. Не требуйте продажи на первом звонке. Если этап неприменим или запись не позволяет оценить его, отмечайте «неприменимо» или «недостаточно данных». Не путайте клиента и сотрудника. Не выводите персональные данные клиента в разборе.',
                'jev_context' => 'Оценивайте смысл, а не дословное совпадение. Не требуйте продажи на первом звонке. Не путайте клиента и сотрудника. Если этап неприменим или данных не хватает, выбирайте соответствующий статус.',
                'criteria' => [
                    ['id' => 'opening', 'title' => 'Начало разговора', 'weight' => 10, 'description' => 'Сотрудник представился, назвал компанию и объяснил повод обращения.', 'jev_question' => 'Насколько сотрудник представился, назвал компанию и объяснил повод обращения?'],
                    ['id' => 'context', 'title' => 'Контекст и контакт', 'weight' => 10, 'description' => 'Уточнил роль собеседника и возможность обсудить задачу.', 'jev_question' => 'Насколько сотрудник уточнил роль собеседника и возможность обсудить задачу?'],
                    ['id' => 'needs', 'title' => 'Выявление потребности', 'weight' => 25, 'description' => 'Задал открытые вопросы о текущей ситуации, проблеме и желаемом результате, выслушал ответы.', 'jev_question' => 'Насколько сотрудник выяснил ситуацию, проблему и желаемый результат клиента, выслушав ответы?'],
                    ['id' => 'value', 'title' => 'Связь предложения с задачей', 'weight' => 20, 'description' => 'Объяснил пользу предложения применительно к сказанному клиентом, без неподтверждённых обещаний.', 'jev_question' => 'Насколько сотрудник связал предложение с задачей клиента без неподтверждённых обещаний?'],
                    ['id' => 'objections', 'title' => 'Работа с возражениями', 'weight' => 15, 'description' => 'Если были возражения: уточнил причину, ответил по существу и проверил, снят ли вопрос. Если возражений не было, критерий неприменим.', 'jev_question' => 'Если клиент возражал, насколько сотрудник уточнил причину, ответил по существу и проверил результат? Если возражений не было, критерий неприменим.'],
                    ['id' => 'next_step', 'title' => 'Следующий шаг', 'weight' => 15, 'description' => 'Согласовал конкретное действие, ответственного и срок либо корректно зафиксировал отказ.', 'jev_question' => 'Насколько сотрудник согласовал конкретный следующий шаг, ответственного и срок либо корректно принял отказ?'],
                    ['id' => 'communication', 'title' => 'Качество общения', 'weight' => 5, 'description' => 'Говорил уважительно и понятно, учитывал ответы клиента. Не делайте выводов об интонации только по тексту.', 'jev_question' => 'Насколько текст разговора подтверждает уважительное и понятное общение с учётом ответов клиента? Не оценивай интонацию.'],
                ],
            ],
        ];
    }

    public function config(): array
    {
        $config = array_replace($this->defaults(), Setting::find('call_library')?->value ?? []);
        if (! isset($config['rubric']['jev_context'])) {
            $config['rubric']['jev_context'] = mb_substr($config['rubric']['instructions'], 0, 400);
        }
        foreach ($config['rubric']['criteria'] as &$criterion) {
            if (! isset($criterion['jev_question'])) {
                $criterion['jev_question'] = 'Насколько сотрудник выполнил критерий: '.mb_substr(rtrim($criterion['description'], '. '), 0, 900).'?';
            }
            $criterion = collect($criterion)->only(['id', 'title', 'description', 'jev_question', 'weight'])->all();
        }
        unset($criterion);
        $config['rubric'] = collect($config['rubric'])->only(['version', 'title', 'instructions', 'jev_context', 'provisional', 'criteria'])->all();

        return $config;
    }

    public function publicConfig(): array
    {
        $c = $this->config();
        unset($c['secret'], $c['owner_id']);
        $c['configured'] = $this->configured();
        $c['evaluation_provider'] = 'openrouter';
        $c['evaluation_model'] = CallEvaluation::MODEL;
        $c['explanation_model'] = app(OpenRouter::class)->config()['chat_model'] ?? 'openai/gpt-4.1-mini';
        $c['evaluation_configured'] = app(CallEvaluation::class)->ready();
        $c['sync'] = Setting::find('sipuni_sync')?->value ?? [];

        return $c;
    }

    public function configured(): bool
    {
        $c = $this->config();

        return $c['account'] !== '' && $c['secret'] !== '';
    }

    public function requireRead(User $user, CallRecording $call): void
    {
        abort_unless($user->canViewCalls(), 404);
        if ($call->page_id) {
            app(Access::class)->requireRead($user, Page::findOrFail($call->page_id));
        }
    }

    public function requireListen(User $user, CallRecording $call): void
    {
        $this->requireRead($user, $call);
        abort_unless($user->canListenCalls(), 404);
    }

    public function requireEdit(User $user, CallRecording $call): void
    {
        $this->requireRead($user, $call);
        abort_unless($user->canEdit(), 403);
    }

    public function queueEvaluation(CallRecording $call, bool $manual = false, ?User $requestedBy = null): void
    {
        DB::transaction(function () use ($call, $manual, $requestedBy): void {
            $call = CallRecording::whereKey($call->id)->lockForUpdate()->firstOrFail();
            $config = $this->config();
            if (! $manual && ! $config['evaluation_enabled']) {
                if ($call->evaluation_status !== 'ready') {
                    $call->update(['evaluation_status' => 'paused', 'evaluation_error' => null]);
                }

                return;
            }
            if ($call->status !== 'ready') {
                abort_if($manual, 422, 'Дождитесь готовности расшифровки.');

                return;
            }
            if (in_array($call->evaluation_status, ['queued', 'evaluating', 'retrying'])) {
                abort_if($manual, 409, 'Оценка уже выполняется.');

                return;
            }
            $run = CallEvaluationRun::create([
                'call_recording_id' => $call->id, 'requested_by' => $requestedBy?->id ?? $call->user_id,
                'trigger' => $manual ? 'manual' : 'automatic', 'status' => 'queued',
                'rubric_snapshot' => $config['rubric'], 'jev_answers' => [],
            ]);
            $call->update(['evaluation_status' => 'queued', 'evaluation_error' => null]);
            EvaluateCall::dispatch($call->id, $run->id)->afterCommit();
        });
    }
}

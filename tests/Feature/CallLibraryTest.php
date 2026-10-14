<?php

namespace Tests\Feature;

use App\Jobs\EvaluateCall;
use App\Jobs\ImportSipuni;
use App\Jobs\TranscribeCall;
use App\Models\Asset;
use App\Models\CallEvaluationRun;
use App\Models\CallRecording;
use App\Models\Setting;
use App\Models\User;
use App\Services\CallEvaluation;
use App\Services\CallLibrary;
use App\Services\CallTranscription;
use App\Services\Knowledge;
use App\Services\Sipuni;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CallLibraryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->travelTo(now()->setDate(2026, 9, 21)->setTime(12, 0));
    }

    private function configure(array $overrides = []): array
    {
        $config = array_replace(app(CallLibrary::class)->defaults(), ['enabled' => true, 'evaluation_enabled' => true, 'account' => '012345', 'secret' => 'fixture-secret', 'owner_id' => $this->admin->id, 'start_date' => '2026-09-20'], $overrides);
        Setting::updateOrCreate(['key' => 'call_library'], ['value' => $config]);

        return $config;
    }

    private function recording(array $overrides = []): CallRecording
    {
        return CallRecording::create([...['page_id' => null, 'user_id' => $this->admin->id, 'source_url' => '', 'source_host' => 'sipuni.com', 'title' => 'Разговор', 'external_id' => '012345:fixture', 'status' => 'ready', 'transcript' => 'Добрый день. Какая у вас задача? Обсудим завтра.', 'segments' => [['text' => 'Какая у вас задача?', 'start' => 3.0, 'end' => 6.0]]], ...$overrides]);
    }

    public function test_import_uses_talk_time_strictly_and_deduplicates_without_storing_customer_numbers(): void
    {
        $this->configure();
        $csv = "Тип;Время;Длительность звонка, сек;Длительность разговора, сек;ID записи;Откуда\nИсходящий;20.09.2026 11:00:00;90;10;ringing;79991234567;\nИсходящий;20.09.2026 11:01:00;80;40;boundary;79991234567;\nВходящий;20.09.2026 11:02:00;70;41;accepted;79991234567;\nВходящий;20.09.2026 11:03:00;100;;unknown;79991234567;\n";
        Http::fake(['sipuni.com/api/statistic/export' => Http::response($csv)]);

        $result = app(Sipuni::class)->sync();
        app(Sipuni::class)->sync();

        $this->assertSame(1, $result['imported']);
        $this->assertSame(2, $result['skipped']);
        $call = CallRecording::sole();
        $this->assertSame(41, $call->talk_seconds);
        $this->assertNull($call->page_id);
        $this->assertSame('incoming', $call->direction);
        $this->assertStringNotContainsString('79991234567', json_encode($call->getAttributes()));
        Queue::assertPushed(TranscribeCall::class, 1);
        Http::assertSent(fn ($r) => $r['state'] === '2' && $r['type'] === '0' && $r['from'] === '20.09.2026' && ! isset($r['secret']) && strlen($r['hash']) === 32);
    }

    public function test_settings_are_admin_only_encrypted_and_threshold_change_rechecks_the_period(): void
    {
        $config = $this->configure();
        Setting::create(['key' => 'sipuni_sync', 'value' => ['through' => '2026-09-21']]);
        $reader = User::factory()->create(['role' => 'reader']);
        $this->actingAs($reader)->getJson('/api/call-library/settings')->assertForbidden();
        $this->putJson('/api/call-library/settings', $config)->assertForbidden();
        $this->postJson('/api/call-library/sync')->assertForbidden();
        $config['min_talk_seconds'] = 60;
        $config['secret'] = '';
        $config['rubric']['criteria'][0]['jev_question'] = 'Новый вопрос к JEV о начале разговора?';
        $this->actingAs($this->admin)->putJson('/api/call-library/settings', $config)->assertOk()->assertJsonPath('min_talk_seconds', 60)->assertJsonPath('rubric.version', 2)->assertJsonPath('rubric.criteria.0.jev_question', 'Новый вопрос к JEV о начале разговора?')->assertJsonMissingPath('secret');
        $this->assertSame('fixture-secret', app(CallLibrary::class)->config()['secret']);
        $this->assertStringNotContainsString('fixture-secret', DB::table('settings')->where('key', 'call_library')->value('value'));
        $this->assertNull(Setting::find('sipuni_sync'));
        $this->putJson('/api/call-library/settings', $config)->assertConflict();
        $this->postJson('/api/call-library/sync')->assertOk()->assertJsonPath('queued', true);
        Queue::assertPushed(ImportSipuni::class);
    }

    public function test_empty_jev_question_is_rejected(): void
    {
        $config = $this->configure();
        $config['rubric']['criteria'][0]['jev_question'] = '';

        $this->actingAs($this->admin)->putJson('/api/call-library/settings', $config)->assertUnprocessable()->assertJsonValidationErrors('rubric.criteria.0.jev_question');
    }

    public function test_missing_talk_duration_is_not_replaced_by_total_duration_and_does_not_advance_cursor(): void
    {
        $this->configure();
        Http::fake(['sipuni.com/api/statistic/export' => Http::response("Время;Длительность звонка;ID записи\n20.09.2026 11:00:00;100;recording\n")]);
        try {
            app(Sipuni::class)->sync();
            $this->fail('Expected unsupported CSV to fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Общая длительность', $e->getMessage());
        }
        $this->assertDatabaseCount('call_recordings', 0);
        $this->assertArrayNotHasKey('through', Setting::find('sipuni_sync')->value);
        Queue::assertNothingPushed();
    }

    public function test_existing_rubric_gets_editable_jev_questions_without_an_unintended_version_change(): void
    {
        $legacy = $this->configure();
        foreach ($legacy['rubric']['criteria'] as &$criterion) {
            unset($criterion['jev_question']);
        }
        unset($criterion);
        unset($legacy['rubric']['jev_context']);
        Setting::whereKey('call_library')->firstOrFail()->update(['value' => $legacy]);

        $settings = $this->actingAs($this->admin)->getJson('/api/call-library/settings')->assertOk()->json();
        $this->assertStringContainsString('Насколько сотрудник', $settings['rubric']['criteria'][0]['jev_question']);
        $this->assertNotEmpty($settings['rubric']['jev_context']);
        $this->putJson('/api/call-library/settings', $settings)->assertOk()->assertJsonPath('rubric.version', 1);
    }

    public function test_csv_accepts_time_formatted_duration_and_rejects_partial_rows(): void
    {
        $rows = app(Sipuni::class)->parseCsv("\xEF\xBB\xBFВремя;Длительность разговора;ID записи\n20.09.2026 11:00:00;00:00:41;recording\n", 'Europe/Moscow');
        $this->assertSame(41, $rows[0]['talk_seconds']);
        $this->expectException(\RuntimeException::class);
        app(Sipuni::class)->parseCsv("Время;Длительность разговора;ID записи\n20.09.2026;", 'Europe/Moscow');
    }

    public function test_standalone_transcription_runs_without_a_widget_and_queues_evaluation(): void
    {
        $this->configure();
        $call = $this->recording(['status' => 'queued', 'transcript' => null]);
        $asset = Asset::create(['page_id' => null, 'user_id' => $this->admin->id, 'disk' => 'local', 'path' => 'calls/test.wav', 'name' => 'call.wav', 'mime' => 'audio/wav', 'size' => 13]);
        Storage::disk('local')->put($asset->path, 'audio fixture');
        $call->update(['asset_id' => $asset->id]);
        Setting::create(['key' => 'openrouter', 'value' => ['key' => 'fixture-key']]);
        Http::fake(['openrouter.ai/api/v1/audio/transcriptions' => Http::response(['text' => 'Добрый день', 'duration' => 41])]);

        app()->call([new TranscribeCall($call->id), 'handle']);

        $this->assertSame('ready', $call->fresh()->status);
        $this->assertSame('queued', $call->fresh()->evaluation_status);
        Queue::assertPushed(EvaluateCall::class, 1);
        $this->get('/media/'.$asset->id)->assertUnauthorized();
        $this->actingAs($this->admin)->get('/media/'.$asset->id)->assertOk();
    }

    public function test_evaluation_computes_weighted_score_and_preserves_rubric_version(): void
    {
        $config = $this->configure();
        $config['rubric']['criteria'][0]['jev_question'] = 'Проверил ли сотрудник начало разговора по индивидуальному вопросу?';
        Setting::whereKey('call_library')->firstOrFail()->update(['value' => $config]);
        $call = $this->recording(['evaluation_status' => 'queued']);
        Setting::create(['key' => 'openrouter', 'value' => ['key' => 'fixture-key', 'chat_model' => 'openai/gpt-4.1-mini']]);
        $criteria = array_map(fn ($r) => ['id' => $r['id'], 'reason' => 'Основание по разговору', 'quote' => $r['id'] === 'objections' ? '' : 'Какая у вас задача?'], $config['rubric']['criteria']);
        $answers = [];
        foreach ($config['rubric']['criteria'] as $criterion) {
            $answers[$criterion['id']] = ['type' => 'choice', 'choice' => $criterion['id'] === 'objections' ? 'not_applicable' : ($criterion['id'] === 'needs' ? 'partial' : 'met'), 'confidence' => 0.95, 'probabilities' => ['met' => 0.95]];
        }
        Http::fake([
            'openrouter.ai/api/alpha/decisions' => Http::response(['model' => CallEvaluation::MODEL, 'answers' => $answers]),
            'openrouter.ai/api/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode(['summary' => 'Обсудили задачу.', 'recommendations' => ['Уточнить срок.'], 'criteria' => $criteria])]]]]),
        ]);

        app()->call([new EvaluateCall($call->id), 'handle']);

        $ready = $call->fresh();
        Http::assertSent(fn ($request) => $request->url() === 'https://openrouter.ai/api/alpha/decisions' && $request['model'] === CallEvaluation::MODEL && $request->hasHeader('Authorization', 'Bearer fixture-key'));
        Http::assertSent(fn ($request) => $request->url() === 'https://openrouter.ai/api/alpha/decisions' && str_contains($request['questions']['opening']['instructions'], 'индивидуальному вопросу'));
        $this->assertSame('openrouter', $ready->evaluation['provider']);
        $this->assertSame('Проверил ли сотрудник начало разговора по индивидуальному вопросу?', $ready->rubric_snapshot['criteria'][0]['jev_question']);
        $this->assertSame(0.95, $ready->evaluation['criteria'][0]['confidence']);
        $this->assertSame(85, $ready->score);
        $this->assertSame('ready', $ready->evaluation_status);
        $this->assertSame(1, $ready->rubric_snapshot['version']);
        $this->assertSame(3, $ready->evaluation['criteria'][0]['start']);
        $config['rubric']['version'] = 2;
        Setting::whereKey('call_library')->first()->update(['value' => $config]);
        $this->assertSame(1, $call->fresh()->rubric_snapshot['version']);
    }

    public function test_fabricated_quote_is_rejected_and_insufficient_evidence_has_no_score(): void
    {
        $call = $this->recording();
        $rubric = ['criteria' => [['id' => 'need', 'title' => 'Потребность', 'weight' => 100, 'description' => 'Уточнить задачу']]];
        $result = ['summary' => 'Разговор', 'recommendations' => [], 'criteria' => [['id' => 'need', 'status' => 'insufficient_evidence', 'reason' => 'Неясно', 'quote' => '']]];
        $safe = app(CallEvaluation::class)->validateResult($result, $call, $rubric);
        $this->assertNull($safe['score']);
        $result['criteria'][0] = ['id' => 'need', 'status' => 'met', 'reason' => 'Все хорошо', 'quote' => 'Клиент согласился купить.'];
        $this->expectException(\RuntimeException::class);
        app(CallEvaluation::class)->validateResult($result, $call, $rubric);
    }

    public function test_partial_aggregate_score_shows_how_many_weighted_criteria_were_assessed(): void
    {
        $call = $this->recording();
        $rubric = ['criteria' => [
            ['id' => 'opening', 'title' => 'Начало', 'weight' => 20],
            ['id' => 'needs', 'title' => 'Потребность', 'weight' => 30],
            ['id' => 'next_step', 'title' => 'Следующий шаг', 'weight' => 50],
        ]];
        $result = ['summary' => 'Разговор', 'recommendations' => [], 'criteria' => [
            ['id' => 'opening', 'status' => 'met', 'reason' => 'Задан вопрос', 'quote' => 'Какая у вас задача?'],
            ['id' => 'needs', 'status' => 'missed', 'reason' => 'Не уточнил', 'quote' => ''],
            ['id' => 'next_step', 'status' => 'insufficient_evidence', 'reason' => 'Неясно', 'quote' => ''],
        ]];

        $safe = app(CallEvaluation::class)->validateResult($result, $call, $rubric);

        $this->assertSame(40, $safe['score']);
        $this->assertFalse($safe['score_complete']);
        $this->assertSame(2, $safe['answered_count']);
        $this->assertSame(3, $safe['total_count']);
        $this->assertSame(1, $safe['insufficient_count']);
        $this->assertSame(50, $safe['coverage_percent']);
    }

    public function test_manual_evaluation_works_with_auto_mode_off_and_preserves_each_jev_answer_log(): void
    {
        $rubric = ['version' => 1, 'title' => 'Проверка потребности', 'provisional' => false,
            'instructions' => 'Оцени текст', 'jev_context' => 'Учитывай только реплики сотрудника',
            'criteria' => [['id' => 'need', 'title' => 'Потребность', 'description' => 'Уточнил задачу', 'jev_question' => 'Спросил ли о задаче?', 'weight' => 100]]];
        $this->configure(['evaluation_enabled' => false, 'rubric' => $rubric]);
        Setting::create(['key' => 'openrouter', 'value' => ['key' => 'fixture-key', 'chat_model' => 'openai/gpt-4.1-mini']]);
        $call = $this->recording(['evaluation_status' => 'paused']);
        Http::fake([
            'openrouter.ai/api/alpha/decisions' => Http::response(['answers' => ['need' => ['type' => 'choice', 'choice' => 'met', 'confidence' => 0.94, 'probabilities' => ['met' => 0.94]]]]),
            'openrouter.ai/api/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode(['summary' => 'Уточнил задачу.', 'recommendations' => [], 'criteria' => [['id' => 'need', 'reason' => 'Задан вопрос.', 'quote' => 'Какая у вас задача?']]])]]]]),
        ]);

        $this->actingAs($this->admin)->postJson('/api/calls/'.$call->id.'/evaluate')->assertOk()->assertJsonPath('evaluation_status', 'queued');
        $first = CallEvaluationRun::sole();
        $this->assertSame('manual', $first->trigger);
        $this->assertSame($this->admin->id, $first->requested_by);
        Queue::assertPushed(EvaluateCall::class, fn ($job) => $job->runId === $first->id);
        app()->call([new EvaluateCall($call->id, $first->id), 'handle']);
        $this->assertSame('ready', $first->fresh()->status);
        $this->assertSame('Спросил ли о задаче?', $first->fresh()->jev_answers['need']['question']);
        $this->assertSame('met', $first->fresh()->jev_answers['need']['answer']['choice']);
        $this->assertSame(100, $call->fresh()->score);

        $rubric['version'] = 2;
        $rubric['criteria'][0]['jev_question'] = 'Выяснил ли сотрудник потребность?';
        $config = app(CallLibrary::class)->config();
        Setting::whereKey('call_library')->firstOrFail()->update(['value' => [...$config, 'rubric' => $rubric]]);
        $this->postJson('/api/calls/'.$call->id.'/evaluate')->assertOk()->assertJsonPath('evaluation_status', 'queued');
        $second = CallEvaluationRun::where('id', '!=', $first->id)->sole();
        app()->call([new EvaluateCall($call->id, $second->id), 'handle']);

        $this->assertSame(2, CallEvaluationRun::count());
        $this->assertSame(1, $first->fresh()->rubric_snapshot['version']);
        $this->assertSame('Спросил ли о задаче?', $first->fresh()->jev_answers['need']['question']);
        $this->assertSame(2, $second->fresh()->rubric_snapshot['version']);
        $this->assertSame('Выяснил ли сотрудник потребность?', $second->fresh()->jev_answers['need']['question']);
        $this->getJson('/api/calls/'.$call->id)->assertOk()->assertJsonCount(2, 'evaluation_runs')->assertJsonPath('evaluation_runs.0.id', $second->id);
    }

    public function test_editor_can_queue_more_than_six_distinct_call_evaluations_in_a_minute(): void
    {
        $this->configure();
        Setting::create(['key' => 'openrouter', 'value' => ['key' => 'fixture-key']]);
        $this->actingAs($this->admin);

        for ($index = 1; $index <= 7; $index++) {
            $call = $this->recording(['external_id' => 'fixture-'.$index, 'evaluation_status' => 'paused']);
            $this->postJson('/api/calls/'.$call->id.'/evaluate')->assertOk();
        }

        $this->assertSame(7, CallEvaluationRun::count());
        Queue::assertPushed(EvaluateCall::class, 7);
    }

    public function test_many_jev_questions_are_logged_across_all_batches(): void
    {
        $criteria = [];
        for ($index = 1; $index <= 9; $index++) {
            $criteria[] = ['id' => 'criterion_'.$index, 'title' => 'Пункт '.$index, 'description' => 'Проверить вопрос', 'jev_question' => 'Выполнен пункт '.$index.'?', 'weight' => 1];
        }
        $rubric = ['version' => 1, 'title' => 'Девять вопросов', 'provisional' => false, 'instructions' => 'Оцени текст', 'jev_context' => 'Только реплики сотрудника', 'criteria' => $criteria];
        $this->configure(['rubric' => $rubric]);
        Setting::create(['key' => 'openrouter', 'value' => ['key' => 'fixture-key', 'chat_model' => 'openai/gpt-4.1-mini']]);
        $call = $this->recording(['evaluation_status' => 'queued']);
        Http::fake(function ($request) use ($criteria) {
            if (str_contains($request->url(), '/api/alpha/decisions')) {
                return Http::response(['answers' => collect(array_keys($request['questions']))->mapWithKeys(fn ($id) => [$id => ['type' => 'choice', 'choice' => 'met', 'confidence' => 0.97]])->all()]);
            }

            return Http::response(['choices' => [['message' => ['content' => json_encode(['summary' => 'Разговор оценён.', 'recommendations' => [], 'criteria' => array_map(fn ($criterion) => ['id' => $criterion['id'], 'reason' => 'Выполнено.', 'quote' => 'Какая у вас задача?'], $criteria)])]]]]);
        });

        app()->call([new EvaluateCall($call->id), 'handle']);

        $this->assertSame(100, $call->fresh()->score);
        $this->assertCount(9, CallEvaluationRun::sole()->jev_answers);
        $this->assertSame('Выполнен пункт 9?', CallEvaluationRun::sole()->jev_answers['criterion_9']['question']);
        Http::assertSentCount(3);
    }

    public function test_detailed_rubric_scores_and_logs_every_question_across_three_batches(): void
    {
        $rubric = json_decode(file_get_contents(base_path('resources/call-evaluation-rubric-v2.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->configure(['rubric' => $rubric]);
        Setting::create(['key' => 'openrouter', 'value' => ['key' => 'fixture-key', 'chat_model' => 'openai/gpt-4.1-mini']]);
        $call = $this->recording(['evaluation_status' => 'queued']);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/api/alpha/decisions')) {
                return Http::response(['answers' => collect(array_keys($request['questions']))->mapWithKeys(fn ($id) => [$id => ['type' => 'choice', 'choice' => 'met', 'confidence' => 0.9]])->all()]);
            }

            return Http::response([], 503);
        });

        app()->call([new EvaluateCall($call->id), 'handle']);

        $this->assertSame(100, $call->fresh()->score);
        $this->assertSame(2, $call->fresh()->rubric_snapshot['version']);
        $this->assertCount(23, CallEvaluationRun::sole()->jev_answers);
        Http::assertSentCount(4);
    }

    public function test_uncertain_jev_choice_produces_a_preliminary_score(): void
    {
        $this->configure(['rubric' => ['version' => 1, 'title' => 'Потребность', 'provisional' => true, 'instructions' => 'Уточнить задачу', 'criteria' => [['id' => 'need', 'title' => 'Потребность', 'weight' => 100, 'description' => 'Уточнить задачу']]]]);
        $call = $this->recording(['evaluation_status' => 'queued']);
        Setting::create(['key' => 'openrouter', 'value' => ['key' => 'fixture-key', 'chat_model' => 'openai/gpt-4.1-mini']]);
        Http::fake([
            'openrouter.ai/api/alpha/decisions' => Http::response(['answers' => ['need' => ['type' => 'choice', 'choice' => 'met', 'confidence' => 0.51, 'probabilities' => ['met' => 0.51, 'partial' => 0.49]]]]),
            'openrouter.ai/api/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode(['summary' => 'Обсудили задачу.', 'recommendations' => [], 'criteria' => [['id' => 'need', 'reason' => 'Требуется проверка.', 'quote' => '']]])]]]]),
        ]);

        app()->call([new EvaluateCall($call->id), 'handle']);

        $this->assertSame('met', $call->fresh()->evaluation['criteria'][0]['status']);
        $this->assertSame(100, $call->fresh()->score);
        $this->assertFalse($call->fresh()->evaluation['score_complete']);
        $this->assertSame(1, $call->fresh()->evaluation['low_confidence_count']);
    }

    public function test_unsupported_explanation_quote_is_removed_and_score_is_marked_preliminary(): void
    {
        $this->configure(['rubric' => ['version' => 1, 'title' => 'Потребность', 'provisional' => true, 'instructions' => 'Уточнить задачу', 'criteria' => [['id' => 'need', 'title' => 'Потребность', 'weight' => 100, 'description' => 'Уточнить задачу']]]]);
        $call = $this->recording(['evaluation_status' => 'queued']);
        Setting::create(['key' => 'openrouter', 'value' => ['key' => 'fixture-key', 'chat_model' => 'openai/gpt-4.1-mini']]);
        Http::fake([
            'openrouter.ai/api/alpha/decisions' => Http::response(['answers' => ['need' => ['type' => 'choice', 'choice' => 'met', 'confidence' => 0.99]]]),
            'openrouter.ai/api/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode(['summary' => 'Обсудили задачу.', 'recommendations' => [], 'criteria' => [['id' => 'need', 'reason' => 'Клиент согласился.', 'quote' => 'Клиент согласился купить.']]])]]]]),
        ]);

        app()->call([new EvaluateCall($call->id), 'handle']);

        $this->assertSame('met', $call->fresh()->evaluation['criteria'][0]['status']);
        $this->assertSame('', $call->fresh()->evaluation['criteria'][0]['quote']);
        $this->assertSame('', $call->fresh()->evaluation['criteria'][0]['reason']);
        $this->assertSame(100, $call->fresh()->score);
        $this->assertSame(1, $call->fresh()->evaluation['unverified_count']);
        $this->assertFalse($call->fresh()->evaluation['score_complete']);
    }

    public function test_failed_explanation_can_finish_using_saved_jev_answers(): void
    {
        $rubric = ['version' => 1, 'title' => 'Потребность', 'provisional' => true, 'instructions' => 'Уточнить задачу',
            'criteria' => [['id' => 'need', 'title' => 'Потребность', 'weight' => 100, 'description' => 'Уточнить задачу']]];
        $this->configure(['rubric' => $rubric]);
        Setting::create(['key' => 'openrouter', 'value' => ['key' => 'fixture-key', 'chat_model' => 'openai/gpt-4.1-mini']]);
        $call = $this->recording(['evaluation_status' => 'failed']);
        $run = CallEvaluationRun::create(['call_recording_id' => $call->id, 'requested_by' => $this->admin->id,
            'trigger' => 'manual', 'status' => 'failed', 'rubric_snapshot' => $rubric,
            'jev_answers' => ['need' => ['title' => 'Потребность', 'question' => 'Уточнить задачу',
                'answer' => ['type' => 'choice', 'choice' => 'partial', 'confidence' => 0.63]]]]);
        Http::fake(['openrouter.ai/api/v1/chat/completions' => Http::response([], 503)]);

        app()->call([new EvaluateCall($call->id, $run->id), 'handle']);

        $this->assertSame('ready', $call->fresh()->evaluation_status);
        $this->assertSame(50, $call->fresh()->score);
        $this->assertSame('', $call->fresh()->evaluation['criteria'][0]['reason']);
        $this->assertSame('partial', $call->fresh()->evaluation['criteria'][0]['status']);
        $this->assertSame(50, $call->fresh()->evaluation['criteria'][0]['points']);
        $this->assertSame('ready', $run->fresh()->status);
        $this->assertSame(50, $run->fresh()->score);
        $this->assertSame(1, $call->fresh()->evaluation['unverified_count']);
        Http::assertSentCount(1);
    }

    public function test_jev_retries_a_transient_provider_error(): void
    {
        $rubric = ['version' => 1, 'title' => 'Потребность', 'provisional' => true, 'instructions' => 'Уточнить задачу',
            'criteria' => [['id' => 'need', 'title' => 'Потребность', 'weight' => 100, 'description' => 'Уточнить задачу']]];
        $this->configure(['rubric' => $rubric]);
        Setting::create(['key' => 'openrouter', 'value' => ['key' => 'fixture-key', 'chat_model' => 'openai/gpt-4.1-mini']]);
        $call = $this->recording(['evaluation_status' => 'queued']);
        $attempts = 0;
        Http::fake(function ($request) use (&$attempts) {
            if (str_contains($request->url(), '/api/alpha/decisions')) {
                $attempts++;

                return $attempts === 1 ? Http::response([], 503) : Http::response(['answers' => ['need' => ['type' => 'choice', 'choice' => 'met', 'confidence' => 0.9]]]);
            }

            return Http::response([], 503);
        });

        app()->call([new EvaluateCall($call->id), 'handle']);

        $this->assertSame(2, $attempts);
        $this->assertSame(100, $call->fresh()->score);
    }

    public function test_failed_repeat_keeps_the_previous_score_visible(): void
    {
        $call = $this->recording(['evaluation_status' => 'retrying', 'score' => 63, 'evaluation' => ['summary' => 'Прежний разбор']]);
        $run = CallEvaluationRun::create(['call_recording_id' => $call->id, 'requested_by' => $this->admin->id,
            'trigger' => 'manual', 'status' => 'retrying', 'rubric_snapshot' => ['version' => 2], 'jev_answers' => []]);

        (new EvaluateCall($call->id, $run->id))->failed(null);

        $this->assertSame('ready', $call->fresh()->evaluation_status);
        $this->assertSame(63, $call->fresh()->score);
        $this->assertSame('failed', $run->fresh()->status);
    }

    public function test_reference_reuses_call_without_jobs_and_public_page_does_not_reveal_the_recording(): void
    {
        $call = $this->recording(['evaluation_status' => 'ready', 'score' => 75]);
        $kb = app(Knowledge::class);
        $page = $kb->save($this->admin, ['title' => 'Пример разговора', 'document' => ['type' => 'doc', 'content' => []], 'reason' => 'Создание']);
        $request = ['recording_id' => $call->id, 'expected_revision' => $page->revision, 'request_id' => 'attach-call-once'];
        $this->actingAs($this->admin)->postJson('/api/pages/'.$page->id.'/call-references', $request)->assertOk();
        $this->postJson('/api/pages/'.$page->id.'/call-references', $request)->assertOk();
        $this->assertDatabaseCount('call_recordings', 1);
        $this->assertSame('callReference', $page->fresh()->document['content'][0]['type']);
        Queue::assertNotPushed(TranscribeCall::class);
        Queue::assertNotPushed(EvaluateCall::class);
        $pub = $kb->publish($this->admin, $page->fresh(), 2, false);
        $this->get('/p/'.$pub->slug)->assertOk()->assertSee('Открыть разбор звонка')->assertDontSee('Какая у вас задача?');
        $reader = User::factory()->create(['role' => 'reader']);
        $this->actingAs($reader)->postJson('/api/calls/'.$call->id.'/evaluate')->assertNotFound();
        $this->postJson('/api/pages/'.$page->id.'/call-references', $request)->assertForbidden();
    }

    public function test_restricted_legacy_calls_stay_hidden_in_list_and_cannot_be_referenced(): void
    {
        $page = app(Knowledge::class)->save($this->admin, ['title' => 'Закрыто', 'visibility' => 'restricted', 'document' => ['type' => 'doc', 'content' => []], 'reason' => 'Создание']);
        $call = $this->recording(['page_id' => $page->id, 'evaluation_status' => 'ready']);
        $reader = User::factory()->create(['role' => 'reader']);
        $reader->update(['can_view_calls' => true]);
        $this->actingAs($reader)->getJson('/api/calls')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/calls/'.$call->id)->assertNotFound();
    }

    public function test_admin_can_grant_view_and_listen_separately_and_media_checks_both(): void
    {
        $call = $this->recording();
        $asset = Asset::create(['page_id' => null, 'user_id' => $this->admin->id, 'disk' => 'local', 'path' => 'calls/permissions.wav', 'name' => 'call.wav', 'mime' => 'audio/wav', 'size' => 13]);
        Storage::disk('local')->put($asset->path, 'audio fixture');
        $call->update(['asset_id' => $asset->id]);
        $reader = User::factory()->create(['role' => 'reader']);

        $this->actingAs($reader)->getJson('/api/calls')->assertForbidden();
        $this->getJson('/api/calls/'.$call->id)->assertNotFound();
        $this->get('/media/'.$asset->id)->assertNotFound();
        $this->putJson('/api/users/'.$reader->id.'/call-permissions', ['can_view_calls' => true, 'can_listen_calls' => true])->assertForbidden();

        $this->actingAs($this->admin)->putJson('/api/users/'.$reader->id.'/call-permissions', ['can_view_calls' => false, 'can_listen_calls' => true])->assertUnprocessable();
        $this->putJson('/api/users/'.$reader->id.'/call-permissions', ['can_view_calls' => true, 'can_listen_calls' => false])->assertOk();
        $this->actingAs($reader->fresh())->getJson('/api/calls')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/calls/'.$call->id)->assertOk()->assertJsonPath('transcript', $call->transcript);
        $this->get('/media/'.$asset->id)->assertNotFound();

        $this->actingAs($this->admin)->putJson('/api/users/'.$reader->id.'/call-permissions', ['can_view_calls' => true, 'can_listen_calls' => true])->assertOk();
        $this->actingAs($reader->fresh())->get('/media/'.$asset->id)->assertOk();
        $this->actingAs($this->admin)->putJson('/api/users/'.$reader->id.'/call-permissions', ['can_view_calls' => false, 'can_listen_calls' => false])->assertOk();
        $this->actingAs($reader->fresh())->getJson('/api/calls')->assertForbidden();
        $this->get('/media/'.$asset->id)->assertNotFound();
    }

    public function test_quote_jump_uses_actual_word_timestamps(): void
    {
        $call = $this->recording(['segments' => [], 'words' => [
            ['word' => 'Какая', 'start' => 12.5, 'end' => 13.0],
            ['word' => 'у', 'start' => 13.0, 'end' => 13.1],
            ['word' => 'вас', 'start' => 13.1, 'end' => 13.2],
            ['word' => 'задача?', 'start' => 13.2, 'end' => 14.0],
        ]]);
        $rubric = ['criteria' => [['id' => 'need', 'title' => 'Потребность', 'weight' => 100, 'description' => 'Уточнить задачу']]];
        $result = ['summary' => 'Разговор', 'recommendations' => [], 'criteria' => [['id' => 'need', 'status' => 'met', 'reason' => 'Задан вопрос', 'quote' => 'Какая у вас задача?']]];
        $safe = app(CallEvaluation::class)->validateResult($result, $call, $rubric);
        $this->assertSame(12.5, $safe['criteria'][0]['start']);
        $this->assertSame(100, $safe['score']);
    }

    public function test_registry_orders_imported_calls_by_conversation_time(): void
    {
        $newer = $this->recording(['external_id' => '012345:newer', 'called_at' => '2026-09-19 10:00:00']);
        $this->recording(['external_id' => '012345:older', 'called_at' => '2026-09-15 10:00:00']);
        $this->actingAs($this->admin)->getJson('/api/calls')->assertOk()->assertJsonPath('data.0.id', $newer->id);
    }

    public function test_evaluation_is_disabled_by_default_and_queued_jobs_make_no_ai_requests(): void
    {
        $call = $this->recording(['evaluation_status' => 'queued']);
        app()->call([new EvaluateCall($call->id), 'handle']);
        $this->assertSame('paused', $call->fresh()->evaluation_status);
        $this->assertNull($call->fresh()->evaluation);
        $this->actingAs($this->admin)->postJson('/api/calls/'.$call->id.'/evaluate')->assertUnprocessable();
        app(CallLibrary::class)->queueEvaluation($call->fresh());
        Queue::assertNotPushed(EvaluateCall::class);
        Http::assertNothingSent();
    }

    public function test_enabled_jev_evaluation_waits_for_openrouter_key_even_if_xai_is_configured(): void
    {
        $this->configure();
        Setting::create(['key' => 'transcription', 'value' => ['provider' => 'xai', 'xai_key' => 'unused-key', 'xai_model' => CallTranscription::XAI_MODEL]]);
        $call = $this->recording(['evaluation_status' => 'queued']);
        app()->call([new EvaluateCall($call->id), 'handle']);
        $this->assertSame('waiting_config', $call->fresh()->evaluation_status);
        Http::assertNothingSent();
    }

    public function test_sipuni_backfills_employee_names_without_retranscribing_existing_calls(): void
    {
        $this->configure(['evaluation_enabled' => false]);
        $existing = $this->recording(['external_id' => '012345:outbound', 'evaluation_status' => 'paused']);
        $csv = "Тип;Время;Длительность разговора, сек;ID записи;Откуда;Кто ответил\nИсходящий;20.09.2026 11:00:00;65;outbound;203 (Анна Тестовая);79991234567\nВходящий;20.09.2026 12:00:00;90;inbound;79991234567;204 (Иван Тестовый)\n";
        Http::fake(['sipuni.com/api/statistic/export' => Http::response($csv)]);
        app(Sipuni::class)->sync();
        app(Sipuni::class)->sync();
        $this->assertSame('Анна Тестовая', $existing->fresh()->operator_name);
        $this->assertSame('paused', $existing->fresh()->evaluation_status);
        $this->assertSame('Иван Тестовый', CallRecording::where('external_id', '012345:inbound')->sole()->operator_name);
        $this->assertStringNotContainsString('79991234567', CallRecording::all()->toJson());
        Queue::assertPushed(TranscribeCall::class, 1);
        Queue::assertNotPushed(EvaluateCall::class);
        Http::assertSent(fn ($request) => $request['names'] === '1');
    }

    public function test_registry_combines_employee_and_inclusive_local_date_filters(): void
    {
        $this->configure(['timezone' => 'Europe/Moscow']);
        $first = $this->recording(['external_id' => '012345:first', 'operator_name' => 'Анна', 'called_at' => '2026-09-20 21:00:00']);
        $last = $this->recording(['external_id' => '012345:last', 'operator_name' => 'Анна', 'called_at' => '2026-09-21 20:59:59']);
        $this->recording(['external_id' => '012345:before', 'operator_name' => 'Анна', 'called_at' => '2026-09-20 20:59:59']);
        $this->recording(['external_id' => '012345:after', 'operator_name' => 'Анна', 'called_at' => '2026-09-21 21:00:00']);
        $this->recording(['external_id' => '012345:other', 'operator_name' => 'Иван', 'called_at' => '2026-09-21 10:00:00']);
        $url = '/api/calls?'.http_build_query(['operator' => 'Анна', 'date_from' => '2026-09-21', 'date_to' => '2026-09-21']);
        $this->actingAs($this->admin)->getJson($url)->assertOk()->assertJsonPath('total', 2)->assertJsonPath('data.0.id', $last->id)->assertJsonPath('data.1.id', $first->id)->assertJsonPath('timezone', 'Europe/Moscow');
        $this->getJson('/api/calls?date_from=2026-09-21&date_to=2026-09-20')->assertUnprocessable()->assertJsonValidationErrors('date_to');
        $this->getJson('/api/calls?date_from=invalid')->assertUnprocessable()->assertJsonValidationErrors('date_from');
        $this->getJson('/api/calls?'.http_build_query(['operator' => "' OR 1=1 --"]))->assertOk()->assertJsonPath('total', 0);
    }

    public function test_personal_review_is_independent_for_each_user_and_can_be_removed(): void
    {
        $call = $this->recording(['evaluation_status' => 'paused']);
        $reader = User::factory()->create(['role' => 'reader']);
        $reader->update(['can_view_calls' => true]);
        $url = '/api/calls/'.$call->id.'/review';
        $this->actingAs($this->admin)->putJson($url, ['reviewed' => true, 'user_id' => $reader->id])->assertOk()->assertJsonPath('id', $call->id)->assertJsonPath('reviewed_at', fn ($value) => $value !== null);
        $this->putJson($url, ['reviewed' => true])->assertOk();
        $this->assertDatabaseCount('call_reviews', 1);
        $this->assertDatabaseHas('call_reviews', ['user_id' => $this->admin->id, 'call_recording_id' => $call->id]);
        $this->getJson('/api/calls?review=reviewed')->assertOk()->assertJsonPath('total', 1);
        $this->actingAs($reader)->getJson('/api/calls/'.$call->id)->assertOk()->assertJsonPath('reviewed_at', null);
        $this->getJson('/api/calls?review=reviewed')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/calls?review=unreviewed')->assertOk()->assertJsonPath('total', 1);
        $this->putJson($url, ['reviewed' => true])->assertOk();
        $this->assertDatabaseCount('call_reviews', 2);
        $this->actingAs($this->admin)->putJson($url, ['reviewed' => false])->assertOk()->assertJsonPath('reviewed_at', null);
        $this->assertDatabaseMissing('call_reviews', ['user_id' => $this->admin->id, 'call_recording_id' => $call->id]);
        $this->assertDatabaseHas('call_reviews', ['user_id' => $reader->id, 'call_recording_id' => $call->id]);
        $this->assertSame('paused', $call->fresh()->evaluation_status);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_personal_reviews_require_authentication_read_access_and_a_boolean(): void
    {
        $page = app(Knowledge::class)->save($this->admin, ['title' => 'Закрыто', 'visibility' => 'restricted', 'document' => ['type' => 'doc', 'content' => []], 'reason' => 'Создание']);
        $call = $this->recording(['page_id' => $page->id, 'operator_name' => 'Закрытый сотрудник']);
        $url = '/api/calls/'.$call->id.'/review';
        $this->putJson($url, ['reviewed' => true])->assertUnauthorized();
        $reader = User::factory()->create(['role' => 'reader']);
        $reader->update(['can_view_calls' => true]);
        $this->actingAs($reader)->putJson($url, ['reviewed' => true])->assertNotFound();
        $this->getJson('/api/calls')->assertOk()->assertJsonPath('operators', []);
        $this->actingAs($this->admin)->putJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('reviewed');
        $this->putJson($url, ['reviewed' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('reviewed');
        $this->assertDatabaseCount('call_reviews', 0);
    }

    public function test_ready_transcripts_with_disabled_evaluation_are_not_pending(): void
    {
        $this->recording(['evaluation_status' => 'paused']);
        $this->actingAs($this->admin)->getJson('/api/calls?state=pending')->assertOk()->assertJsonPath('total', 0);
    }
}

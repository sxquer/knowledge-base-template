<?php

namespace Tests\Feature;

use App\Jobs\TranscribeCall;
use App\Models\Asset;
use App\Models\CallRecording;
use App\Models\Page;
use App\Models\Revision;
use App\Models\Setting;
use App\Models\User;
use App\Services\CallTranscription;
use App\Services\Knowledge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class XaiTranscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    private function recording(): CallRecording
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $page = app(Knowledge::class)->save($admin, ['title' => 'Звонок', 'document' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]], 'reason' => 'Создание']);
        $this->postJson('/api/pages/'.$page->id.'/calls', ['title' => 'Звонок', 'source_url' => 'https://sipuni.com/record?hash=private-source', 'expected_revision' => $page->revision, 'request_id' => 'xai-test-call'])->assertOk();
        $call = CallRecording::sole();
        Storage::disk('local')->put('calls/test.mp3', 'audio fixture');
        $asset = Asset::create(['page_id' => $page->id, 'user_id' => $admin->id, 'disk' => 'local', 'path' => 'calls/test.mp3', 'name' => 'Private customer title.mp3', 'mime' => 'audio/mpeg', 'size' => 13]);
        $call->update(['asset_id' => $asset->id]);

        return $call;
    }

    private function configureXai(string $key = 'xai-test-secret'): void
    {
        Setting::create(['key' => 'transcription', 'value' => ['provider' => 'xai', 'xai_key' => $key, 'xai_model' => CallTranscription::XAI_MODEL]]);
    }

    public function test_xai_uploads_audio_with_diarization_and_preserves_speakers_in_history_and_publication(): void
    {
        $call = $this->recording();
        $this->configureXai();
        config(['services.xai.proxy' => 'socks5h://127.0.0.1:11080']);
        $optionsSeen = null;
        $requestBody = '';
        Http::fake(['https://api.x.ai/v1/stt' => function ($request, $options) use (&$optionsSeen, &$requestBody) {
            $optionsSeen = $options;
            $requestBody = $request->body();

            return Http::response(['text' => 'Добрый день. Здравствуйте.', 'duration' => 3.5, 'words' => [
                ['text' => 'Добрый', 'start' => 0.1, 'end' => 0.7, 'speaker' => 0],
                ['text' => 'день.', 'start' => 0.8, 'end' => 1.2, 'speaker' => 0],
                ['text' => 'Здравствуйте.', 'start' => 1.5, 'end' => 2.4, 'speaker' => 1],
            ]]);
        }]);

        app()->call([new TranscribeCall($call->id), 'handle']);

        $ready = $call->fresh();
        $this->assertSame('ready', $ready->status);
        $this->assertSame('xai', $ready->transcription_provider);
        $this->assertSame(CallTranscription::XAI_MODEL, $ready->transcription_model);
        $this->assertSame([0, 0, 1], array_column($ready->words, 'speaker'));
        $this->assertSame('Здравствуйте.', $ready->words[2]['word']);
        $this->assertSame(3.5, $ready->duration);
        $this->assertSame('socks5h://127.0.0.1:11080', $optionsSeen['proxy']);
        preg_match_all('/Content-Disposition: form-data; name="([^"]+)"/', $requestBody, $fields);
        $this->assertSame(['model', 'diarize', 'language', 'format', 'file'], $fields[1]);
        $this->assertStringContainsString("name=\"diarize\"\r\n\r\ntrue", $requestBody);
        $this->assertStringContainsString('filename="recording.mp3"', $requestBody);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer xai-test-secret'));
        $this->assertStringNotContainsString('private-source', $requestBody);
        $this->assertStringNotContainsString('Private customer', $requestBody);
        $snapshot = Revision::where('page_id', $call->page_id)->where('number', $ready->completion_revision)->sole()->snapshot;
        $this->assertSame(1, $snapshot['document']['content'][1]['attrs']['snapshot']['words'][2]['speaker']);
        $this->assertStringNotContainsString('xai-test-secret', json_encode($snapshot));
        $this->getJson('/api/calls/'.$call->id)->assertJsonPath('words.0.speaker', 0)->assertDontSee('xai-test-secret');
        $page = Page::findOrFail($call->page_id);
        $pub = app(Knowledge::class)->publish(User::findOrFail($call->user_id), $page, $page->revision, false);
        $this->get('/p/'.$pub->slug)->assertOk()->assertSee('Спикер 1')->assertSee('Спикер 2')->assertSee('Здравствуйте.')->assertDontSee('xai-test-secret');
    }

    public function test_transcription_settings_are_admin_only_encrypted_and_keep_the_existing_key(): void
    {
        $call = $this->recording();
        $call->update(['status' => 'waiting_config']);
        $data = ['provider' => 'xai', 'xai_key' => 'xai-test-secret', 'xai_model' => CallTranscription::XAI_MODEL];
        $this->postJson('/logout')->assertRedirect();
        $this->putJson('/api/transcription-settings', $data)->assertUnauthorized();
        foreach (['reader', 'editor'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->putJson('/api/transcription-settings', $data)->assertForbidden();
        }
        $this->assertNull(Setting::find('transcription'));
        $this->actingAs(User::findOrFail($call->user_id))->putJson('/api/transcription-settings', $data)->assertOk()->assertJsonPath('transcription.configured', true)->assertDontSee('xai-test-secret');
        $this->assertStringNotContainsString('xai-test-secret', Setting::findOrFail('transcription')->getRawOriginal('value'));
        $this->assertSame('queued', $call->fresh()->status);
        Queue::assertPushed(TranscribeCall::class, 2);
        $this->putJson('/api/transcription-settings', [...$data, 'xai_key' => null])->assertOk();
        $this->assertSame('xai-test-secret', Setting::findOrFail('transcription')->value['xai_key']);
        $this->getJson('/api/settings')->assertJsonPath('transcription.provider', 'xai')->assertDontSee('xai-test-secret')->assertJsonMissingPath('transcription.xai_key');
        $this->putJson('/api/transcription-settings', [...$data, 'provider' => 'unknown'])->assertUnprocessable();
        $this->putJson('/api/transcription-settings', [...$data, 'xai_model' => 'unknown'])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_missing_xai_key_waits_even_if_openrouter_is_configured(): void
    {
        $call = $this->recording();
        $this->configureXai('');
        Setting::create(['key' => 'openrouter', 'value' => ['key' => 'test-openrouter']]);

        app()->call([new TranscribeCall($call->id), 'handle']);

        $this->assertSame('waiting_config', $call->fresh()->status);
        $this->putJson('/api/transcription-settings', ['provider' => 'xai', 'xai_key' => null, 'xai_model' => CallTranscription::XAI_MODEL])->assertUnprocessable();
        $this->putJson('/api/settings', ['key' => null, 'chat_model' => 'test/chat', 'embedding_model' => 'test/embedding'])->assertOk();
        $this->assertSame('waiting_config', $call->fresh()->status);
        Queue::assertPushed(TranscribeCall::class, 1);
        Http::assertNothingSent();
    }

    public function test_xai_auth_failure_is_safe_non_retryable_and_does_not_fallback(): void
    {
        $call = $this->recording();
        $this->configureXai();
        Setting::create(['key' => 'openrouter', 'value' => ['key' => 'test-openrouter']]);
        Http::fake(['https://api.x.ai/v1/stt' => Http::response(['error' => 'xai-test-secret private-source'], 401)]);

        app()->call([new TranscribeCall($call->id), 'handle']);

        $this->assertSame('failed', $call->fresh()->status);
        $this->assertStringContainsString('xAI: HTTP 401', $call->fresh()->error);
        $this->assertStringNotContainsString('secret', $call->fresh()->error);
        $this->assertStringNotContainsString('private-source', $call->fresh()->error);
        Http::assertSentCount(1);
    }

    public function test_xai_does_not_invent_speakers_or_accept_invalid_timings(): void
    {
        $call = $this->recording();
        $this->configureXai();
        Http::fake(['https://api.x.ai/v1/stt' => Http::response(['text' => 'Текст', 'words' => [
            ['text' => 'Без', 'start' => 0, 'end' => 1],
            ['text' => 'спикера', 'start' => 1, 'end' => 2, 'speaker' => '<script>'],
            ['text' => 'invalid', 'start' => -1, 'end' => 2, 'speaker' => 0],
            ['text' => 'invalid', 'start' => 2, 'end' => 1, 'speaker' => 0],
        ], 'segments' => 'malformed'])]);

        app()->call([new TranscribeCall($call->id), 'handle']);

        $this->assertSame('ready', $call->fresh()->status);
        $this->assertCount(2, $call->fresh()->words);
        foreach ($call->fresh()->words as $word) {
            $this->assertArrayNotHasKey('speaker', $word);
        }
        $this->assertSame([], $call->fresh()->segments);
        Http::assertSentCount(1);
    }

    public function test_explicit_retranscription_keeps_previous_text_and_prevents_duplicate_jobs(): void
    {
        $call = $this->recording();
        $call->update(['status' => 'ready', 'transcript' => 'Прежний текст', 'words' => [['word' => 'Прежний', 'start' => 0, 'end' => 1]]]);
        $this->postJson('/api/calls/'.$call->id.'/retry')->assertConflict();
        $this->postJson('/api/calls/'.$call->id.'/retry', ['retranscribe' => true])->assertOk()->assertJsonPath('status', 'queued')->assertJsonPath('transcript', 'Прежний текст');
        $this->postJson('/api/calls/'.$call->id.'/retry', ['retranscribe' => true])->assertConflict();
        $this->assertSame('Прежний текст', $call->fresh()->transcript);
        Queue::assertPushed(TranscribeCall::class, 2);
        Http::assertNothingSent();
    }
}

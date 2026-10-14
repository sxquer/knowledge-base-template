<?php

namespace Tests\Feature;

use App\Jobs\TranscribeCall;
use App\Models\Asset;
use App\Models\CallRecording;
use App\Models\Chunk;
use App\Models\Page;
use App\Models\Revision;
use App\Models\Setting;
use App\Models\User;
use App\Services\Access;
use App\Services\CallAudio;
use App\Services\Knowledge;
use App\Services\OpenRouter;
use GuzzleHttp\Handler\CurlFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CallRecordingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function page(array $overrides = []): Page
    {
        return app(Knowledge::class)->save($this->admin, [...['title' => 'Разговор', 'document' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]], 'reason' => 'Создание'], ...$overrides]);
    }

    private function createCall(Page $page): CallRecording
    {
        $this->actingAs($this->admin)->postJson('/api/pages/'.$page->id.'/calls', ['title' => 'Первый разговор', 'source_url' => 'https://sipuni.com/recordings/call.mp3?access=private-token', 'expected_revision' => $page->revision, 'request_id' => 'create-'.$page->id])->assertOk()->assertJsonPath('recording.status', 'queued')->assertDontSee('private-token');

        return CallRecording::where('page_id', $page->id)->sole();
    }

    private function attach(CallRecording $call): Asset
    {
        Storage::disk('local')->put('calls/test.wav', 'audio fixture');
        $asset = Asset::create(['page_id' => $call->page_id, 'user_id' => $this->admin->id, 'disk' => 'local', 'path' => 'calls/test.wav', 'name' => 'call.wav', 'mime' => 'audio/wav', 'size' => 13]);
        $call->update(['asset_id' => $asset->id]);

        return $asset;
    }

    private function processCall(CallRecording $call): void
    {
        app()->call([new TranscribeCall($call->id), 'handle']);
    }

    public function test_creation_is_atomic_idempotent_and_hides_private_source_in_history_and_jobs(): void
    {
        $page = $this->page();
        $call = $this->createCall($page);
        $this->assertStringNotContainsString('private-token', DB::table('call_recordings')->value('source_url'));
        $this->assertStringContainsString('private-token', $call->source_url);
        $this->assertStringNotContainsString('private-token', json_encode(Revision::all()->toArray()));
        $this->assertSame($call->id, $page->fresh()->document['content'][1]['attrs']['recordingId']);
        Queue::assertPushed(TranscribeCall::class, fn ($job) => $job->recordingId === $call->id && $job->queue === 'calls' && $job->connection === 'calls');
        $this->createCall($page);
        $this->assertSame(1, CallRecording::count());
        Queue::assertPushed(TranscribeCall::class, 1);
        $this->postJson('/api/pages/'.$page->id.'/calls', ['title' => 'Stale', 'source_url' => 'https://sipuni.com/audio.mp3', 'expected_revision' => 1, 'request_id' => 'another-operation'])->assertConflict();
        $this->assertSame(1, CallRecording::count());
    }

    public function test_reader_can_listen_and_read_but_cannot_add_or_retry_and_restricted_pages_remain_private(): void
    {
        $page = $this->page();
        $call = $this->createCall($page);
        $this->attach($call);
        $call->update(['status' => 'failed']);
        $reader = User::factory()->create(['role' => 'reader']);
        $reader->update(['can_view_calls' => true]);
        $this->actingAs($reader)->getJson('/api/calls/'.$call->id)->assertOk()->assertJsonMissingPath('source_url');
        $this->get('/media/'.$call->asset_id)->assertNotFound();
        $reader->update(['can_listen_calls' => true]);
        $this->actingAs($reader->fresh())->get('/media/'.$call->asset_id)->assertOk();
        $this->postJson('/api/pages/'.$page->id.'/calls', [])->assertForbidden();
        $this->postJson('/api/calls/'.$call->id.'/retry')->assertForbidden();
        $page->update(['visibility' => 'restricted']);
        $this->getJson('/api/calls/'.$call->id)->assertNotFound();
        $this->get('/media/'.$call->asset_id)->assertNotFound();
    }

    public function test_call_transcript_is_hidden_from_history_changes_and_search_without_view_permission(): void
    {
        $page = $this->page();
        $call = $this->createCall($page);
        $call->update(['status' => 'ready', 'transcript' => 'Уникальная закрытая фраза']);
        $current = $page->fresh();
        app(Knowledge::class)->save($this->admin, [...$current->toArray(), 'expected_revision' => $current->revision, 'reason' => 'Обновление звонка'], $current);
        $reader = User::factory()->create(['role' => 'reader']);

        $this->actingAs($reader)->getJson('/api/pages/'.$page->id.'/revisions')->assertOk()->assertDontSee('Уникальная закрытая фраза');
        $this->getJson('/api/changes?since='.now()->subDay()->toDateString())->assertOk()->assertDontSee('Уникальная закрытая фраза');
        $this->getJson('/api/search?q='.urlencode('Уникальная закрытая фраза').'&semantic=0')->assertOk()->assertJsonPath('results', []);
        $token = $reader->createToken('call-permission-test', ['kb:read'])->plainTextToken;
        $this->withToken($token)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'kb_read', 'arguments' => ['mode' => 'history', 'page_id' => $page->id]]])->assertOk()->assertDontSee('Уникальная закрытая фраза');

        $reader->update(['can_view_calls' => true]);
        $this->actingAs($reader->fresh())->getJson('/api/pages/'.$page->id.'/revisions')->assertOk()->assertJsonPath('0.snapshot.document.content.1.attrs.snapshot.transcript', 'Уникальная закрытая фраза');
    }

    public function test_foreign_calls_and_forged_snapshots_cannot_be_attached(): void
    {
        $page = $this->page();
        $call = $this->createCall($page);
        $other = $this->page();
        $doc = ['type' => 'doc', 'content' => [['type' => 'callRecording', 'attrs' => ['recordingId' => $call->id, 'snapshot' => ['transcript' => 'forged']]]]];
        $this->putJson('/api/pages/'.$other->id, [...$other->toArray(), 'document' => $doc, 'expected_revision' => 1, 'reason' => 'Attach'])->assertUnprocessable();
        $page->refresh();
        $this->putJson('/api/pages/'.$page->id, [...$page->toArray(), 'document' => $doc, 'expected_revision' => $page->revision, 'reason' => 'Attach'])->assertOk();
        $this->assertArrayNotHasKey('snapshot', $page->fresh()->document['content'][0]['attrs']);
        $this->assertStringNotContainsString('forged', json_encode(Revision::latest('id')->first()->snapshot));
    }

    public function test_worker_waits_for_key_but_keeps_audio_playable_without_external_requests(): void
    {
        $call = $this->createCall($this->page());
        $this->attach($call);
        $this->processCall($call);
        $this->assertSame('waiting_config', $call->fresh()->status);
        $this->get('/media/'.$call->asset_id)->assertOk();
        Http::assertNothingSent();
        $this->putJson('/api/settings', ['key' => 'test-key', 'chat_model' => 'openai/gpt-4.1-mini', 'embedding_model' => 'openai/text-embedding-3-small'])->assertOk();
        $this->assertSame('queued', $call->fresh()->status);
        Queue::assertPushed(TranscribeCall::class, 2);
    }

    public function test_timed_transcription_is_versioned_searchable_and_does_not_change_public_snapshot(): void
    {
        $page = $this->page();
        $call = $this->createCall($page);
        $asset = $this->attach($call);
        $pub = app(Knowledge::class)->publish($this->admin, $page->fresh(), 2, false);
        Setting::create(['key' => 'openrouter', 'value' => ['key' => 'test-key']]);
        Http::fake(['https://openrouter.ai/api/v1/audio/transcriptions' => Http::response(['text' => 'Добрый день', 'duration' => 2, 'words' => [['word' => 'Добрый', 'start' => 0.1, 'end' => 0.8], ['word' => 'день', 'start' => 0.9, 'end' => 1.3]], 'segments' => [['text' => 'Добрый день', 'start' => 0.1, 'end' => 1.3]]])]);
        $this->processCall($call);
        $ready = $call->fresh();
        $this->assertSame('ready', $ready->status);
        $this->assertSame(0.9, $ready->words[1]['start']);
        $this->assertSame(4, $ready->completion_revision);
        $this->assertStringContainsString('Добрый день', Chunk::where('page_id', $page->id)->pluck('text')->implode(' '));
        $this->assertSame('Добрый день', Revision::where('page_id', $page->id)->where('number', 4)->first()->snapshot['document']['content'][1]['attrs']['snapshot']['transcript']);
        $this->get('/p/'.$pub->slug)->assertOk()->assertDontSee('Добрый день');
        $this->get('/media/'.$asset->id.'?publication='.$pub->id)->assertOk();
        $this->processCall($call);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['model'] === 'openai/whisper-1' && $r['timestamp_granularities'] === ['word', 'segment'] && ! isset($r['provider']) && ! str_contains($r->body(), 'private-token'));
        app(Knowledge::class)->publish($this->admin, $page->fresh(), 4, false);
        $this->get('/p/'.$pub->slug)->assertOk()->assertSee('Добрый', false)->assertSee('data-start="0.9"', false)->assertDontSee('private-token');
        app(Knowledge::class)->unpublish($this->admin, $page->fresh(), 5);
        $this->get('/media/'.$asset->id.'?publication='.$pub->id)->assertNotFound();
    }

    public function test_text_without_word_timestamps_remains_readable_without_invented_alignment(): void
    {
        $call = $this->createCall($this->page());
        $this->attach($call);
        Setting::create(['key' => 'openrouter', 'value' => ['key' => 'test-key']]);
        Http::fake(['https://openrouter.ai/api/v1/audio/transcriptions' => Http::response(['text' => 'Только текст', 'words' => [['word' => 'wrong', 'start' => -1, 'end' => 2]]])]);
        $this->processCall($call);
        $this->assertSame('Только текст', $call->fresh()->transcript);
        $this->assertSame([], $call->fresh()->words);
    }

    public function test_provider_failure_is_retryable_and_does_not_expose_payload_or_secret(): void
    {
        $call = $this->createCall($this->page());
        $this->attach($call);
        Setting::create(['key' => 'openrouter', 'value' => ['key' => 'test-key']]);
        Http::fake(['https://openrouter.ai/api/v1/audio/transcriptions' => Http::response(['error' => 'sensitive provider payload'], 402)]);
        $this->processCall($call);
        $this->assertStringNotContainsString('sensitive', $call->fresh()->error);
        $this->assertSame('failed', $call->fresh()->status);
        $this->assertStringContainsString('402', $call->fresh()->error);
        $this->postJson('/api/calls/'.$call->id.'/retry')->assertOk()->assertJsonPath('status', 'queued');
        $this->postJson('/api/calls/'.$call->id.'/retry')->assertConflict();
    }

    public function test_removed_widget_does_not_send_audio_for_transcription(): void
    {
        $page = $this->page();
        $call = $this->createCall($page);
        $page->update(['document' => ['type' => 'doc', 'content' => []]]);
        $this->processCall($call);
        $this->assertSame('cancelled', $call->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_private_network_urls_are_rejected_and_redirects_are_revalidated(): void
    {
        $page = $this->page();
        foreach (['http://example.com/a.mp3', 'https://127.0.0.1/a.mp3', 'https://10.0.0.1/a.mp3', 'https://[::1]/a', 'https://user:password@example.com/a', 'https://example.com:8080/a'] as $url) {
            $this->actingAs($this->admin)->postJson('/api/pages/'.$page->id.'/calls', ['source_url' => $url, 'title' => 'Call', 'expected_revision' => 1, 'request_id' => 'invalid-url'])->assertUnprocessable();
        }
        $this->assertFalse(app(CallAudio::class)->publicIp('169.254.169.254'));
        $this->assertFalse(app(CallAudio::class)->publicIp('100.100.100.200'));
        $this->assertFalse(app(CallAudio::class)->publicIp('224.0.0.1'));
        $this->assertFalse(app(CallAudio::class)->publicIp('::ffff:127.0.0.1'));
        $this->assertTrue(app(CallAudio::class)->publicIp('8.8.8.8'));
        $audio = $this->partialMock(CallAudio::class, function ($mock): void {
            $mock->shouldReceive('resolve')->once()->with('recordings.example.org')->andReturn('8.8.8.8');
        });
        Http::fake(['https://recordings.example.org/call.mp3' => Http::response('', 302, ['Location' => 'https://127.0.0.1/private'])]);
        try {
            $audio->download('https://recordings.example.org/call.mp3', Storage::disk('local')->path('download'));
            $this->fail('Expected redirect rejection');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('127.0.0.1', $e->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_google_drive_reuses_imported_audio_without_requesting_a_private_url(): void
    {
        $sourcePage = $this->page();
        $sourcePage->update(['source_key' => 'drive:1234567890fileid']);
        $pcm = str_repeat("\0", 320);
        $wav = 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;
        Storage::disk('local')->put('original.wav', $wav);
        Asset::create(['page_id' => $sourcePage->id, 'user_id' => $this->admin->id, 'disk' => 'local', 'path' => 'original.wav', 'name' => 'Call.wav', 'mime' => 'audio/wav', 'size' => strlen($wav)]);
        $target = $this->page();
        $call = CallRecording::factory()->create(['page_id' => $target->id, 'user_id' => $this->admin->id, 'source_url' => 'https://drive.google.com/file/d/1234567890fileid/view']);
        $asset = app(CallAudio::class)->acquire($call, app(Access::class));
        $this->assertSame($target->id, $asset->page_id);
        $this->assertSame($wav, Storage::disk('local')->get($asset->path));
        Http::assertNothingSent();
    }

    public function test_remote_audio_download_uses_supported_transport_options_and_keeps_dns_pinned(): void
    {
        $call = $this->createCall($this->page());
        $pcm = str_repeat("\0", 320);
        $wav = 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;
        $audio = $this->partialMock(CallAudio::class, function ($mock): void {
            $mock->shouldReceive('resolve')->once()->with('sipuni.com')->andReturn('8.8.8.8');
        });
        Http::fake(function ($request, $options) use ($call, $wav) {
            $this->assertSame($call->source_url, $request->url());
            // Exercise the real transport validation that Http::fake normally skips.
            $factory = new CurlFactory(1);
            $handle = $factory->create($request->toPsrRequest(), $options);
            $factory->release($handle);
            $this->assertSame('', $options['proxy']);
            $this->assertSame(['sipuni.com:443:8.8.8.8'], $options['curl'][CURLOPT_RESOLVE]);
            $this->assertFalse($options['allow_redirects']);

            return Http::response($wav, 200, ['Content-Type' => 'audio/wav']);
        });

        $asset = $audio->acquire($call, app(Access::class));

        $this->assertSame('audio/wav', $asset->mime);
        $this->assertSame($wav, Storage::disk('local')->get($asset->path));
        Http::assertSentCount(1);
    }

    public function test_transcription_and_chat_use_configured_provider_proxy(): void
    {
        config(['services.openrouter.proxy' => 'socks5h://127.0.0.1:11080']);
        Setting::create(['key' => 'openrouter', 'value' => ['key' => 'test-key', 'chat_model' => 'openai/gpt-4.1-mini']]);
        $call = $this->createCall($this->page());
        $this->attach($call);
        $requests = [];
        Http::fake(function ($request, $options) use (&$requests) {
            $requests[] = ['url' => $request->url(), 'proxy' => $options['proxy'] ?? null];

            return str_ends_with($request->url(), '/transcriptions')
                ? Http::response(['text' => 'Расшифровка'])
                : Http::response(['choices' => [['message' => ['content' => '{"ok":true}']]]]);
        });
        $this->processCall($call);
        $this->assertTrue(app(OpenRouter::class)->json('Test', [])['ok']);
        $this->assertCount(2, $requests);
        foreach ($requests as $request) {
            $this->assertSame('socks5h://127.0.0.1:11080', $request['proxy']);
        }
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\TranscribeCall;
use App\Models\Asset;
use App\Models\Page;
use App\Models\Revision;
use App\Models\User;
use App\Services\Knowledge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MeetingRecordingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Queue::fake();
    }

    private function wav(): string
    {
        $pcm = str_repeat("\0", 320);

        return 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;
    }

    private function page(User $user): Page
    {
        return app(Knowledge::class)->save($user, ['title' => 'Командная планёрка', 'document' => ['type' => 'doc', 'content' => []], 'reason' => 'Создание']);
    }

    public function test_upload_is_versioned_idempotent_private_and_never_transcribed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $page = $this->page($admin);
        $data = ['title' => 'Планёрка 24 сентября', 'expected_revision' => $page->revision, 'request_id' => 'meeting-upload-one'];
        $file = fn () => UploadedFile::fake()->createWithContent('meeting.wav', $this->wav());

        $this->actingAs($admin)->post('/api/pages/'.$page->id.'/meetings', [...$data, 'file' => $file()])->assertOk()->assertJsonPath('page.revision', 2);
        $asset = Asset::sole();
        $this->assertSame('meeting', $page->fresh()->document['content'][0]['attrs']['kind']);
        $this->assertSame($asset->id, $page->fresh()->document['content'][0]['attrs']['assetId']);
        $this->assertSame('Планёрка 24 сентября', Revision::where('page_id', $page->id)->where('number', 2)->first()->snapshot['document']['content'][0]['attrs']['title']);
        Queue::assertNotPushed(TranscribeCall::class);
        $this->get('/media/'.$asset->id)->assertOk();
        $publication = app(Knowledge::class)->publish($admin, $page->fresh(), 2, false);
        $this->get('/p/'.$publication->slug)->assertOk()->assertSee('Планёрка 24 сентября')->assertSee('<audio', false);
        $this->get('/media/'.$asset->id.'?publication='.$publication->id)->assertOk();

        $this->post('/api/pages/'.$page->id.'/meetings', [...$data, 'file' => $file()])->assertOk();
        $this->assertSame(1, Asset::count());
        $this->assertSame(3, $page->fresh()->revision);
        $this->post('/api/pages/'.$page->id.'/meetings', [...$data, 'title' => 'Иное', 'file' => $file()])->assertConflict();

        $reader = User::factory()->create(['role' => 'reader']);
        $this->actingAs($reader)->post('/api/pages/'.$page->id.'/meetings', [...$data, 'file' => $file()])->assertForbidden();
        $page->update(['visibility' => 'restricted']);
        $this->get('/media/'.$asset->id)->assertNotFound();
    }

    public function test_mcp_adds_meeting_with_write_scope_and_rejects_non_media(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $page = $this->page($admin);
        $arguments = ['page_id' => $page->id, 'title' => 'Планёрка', 'filename' => 'meeting.wav', 'audio_base64' => base64_encode($this->wav()), 'expected_revision' => 1, 'request_id' => 'mcp-meeting-one'];
        $rpc = fn (array $args) => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'kb_add_meeting', 'arguments' => $args]];

        $writeToken = $admin->createToken('write', ['kb:read', 'kb:write'])->plainTextToken;
        $this->withToken($writeToken)->postJson('/mcp', $rpc($arguments))->assertOk()->assertJsonPath('result.isError', false)->assertSee('Планёрка');
        $this->assertSame(1, Asset::count());
        $this->assertSame(2, $page->fresh()->revision);
        Queue::assertNotPushed(TranscribeCall::class);

        $arguments['request_id'] = 'mcp-invalid-file';
        $arguments['audio_base64'] = base64_encode('plain text');
        $this->postJson('/mcp', $rpc($arguments))->assertOk()->assertJsonPath('result.isError', true);
        $this->assertSame(1, Asset::count());
    }

    public function test_mcp_read_only_token_cannot_add_meeting(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $page = $this->page($admin);
        $token = $admin->createToken('read', ['kb:read'])->plainTextToken;
        $arguments = ['page_id' => $page->id, 'title' => 'Планёрка', 'filename' => 'meeting.wav', 'audio_base64' => base64_encode($this->wav()), 'expected_revision' => 1, 'request_id' => 'mcp-read-only'];

        $this->withToken($token)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'kb_add_meeting', 'arguments' => $arguments]])->assertOk()->assertJsonPath('result.isError', true);
        $this->assertSame(0, Asset::count());
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\IndexPage;
use App\Jobs\TranscribeCall;
use App\Models\Asset;
use App\Models\Page;
use App\Models\User;
use App\Services\Knowledge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ImageAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Queue::fake([IndexPage::class, TranscribeCall::class]);
    }

    private function page(User $user, array $extra = []): Page
    {
        return app(Knowledge::class)->save($user, [
            'title' => 'Проверка вставки изображения',
            'document' => ['type' => 'doc', 'content' => [
                ['type' => 'paragraph', 'attrs' => ['id' => 'first-block'], 'content' => [['type' => 'text', 'text' => 'Первый абзац']]],
                ['type' => 'paragraph', 'attrs' => ['id' => 'last-block'], 'content' => [['type' => 'text', 'text' => 'Последний абзац']]],
            ]],
            'reason' => 'Создание тестовой статьи',
            ...$extra,
        ]);
    }

    private function arguments(Page $page, array $extra = []): array
    {
        $file = UploadedFile::fake()->image('example.png', 12, 8);

        return [
            'page_id' => $page->id,
            'filename' => 'example.png',
            'image_base64' => base64_encode(file_get_contents($file->getRealPath())),
            'expected_revision' => $page->revision,
            'reason' => 'Вставка примера',
            'request_id' => 'image-test-request',
            ...$extra,
        ];
    }

    private function token(User $user, array $abilities = ['kb:read', 'kb:write']): void
    {
        $this->withToken($user->createToken('image-test', $abilities)->plainTextToken);
    }

    private function uploadImage(array $arguments): TestResponse
    {
        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'kb_add_image', 'arguments' => $arguments]]);
    }

    private function assertUnchanged(Page $page): void
    {
        $this->assertDatabaseCount('assets', 0);
        $this->assertDatabaseCount('operations', 0);
        $this->assertSame($page->revision, $page->fresh()->revision);
        $this->assertSame([], Storage::disk('local')->allFiles('assets'));
        Queue::assertPushed(IndexPage::class, 1);
        Queue::assertNotPushed(TranscribeCall::class);
    }

    public function test_mcp_catalog_exposes_image_upload_and_required_arguments(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->token($admin);

        $response = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        $tool = collect($response->json('result.tools'))->firstWhere('name', 'kb_add_image');

        $this->assertNotNull($tool);
        $this->assertContains('expected_revision', $tool['inputSchema']['required']);
        $this->assertContains('image_base64', $tool['inputSchema']['required']);
        $this->assertArrayHasKey('after_block_id', $tool['inputSchema']['properties']);
    }

    public static function imageFormats(): array
    {
        return ['PNG' => ['png', 'image/png'], 'JPEG' => ['jpg', 'image/jpeg'], 'WebP' => ['webp', 'image/webp'], 'GIF' => ['gif', 'image/gif']];
    }

    #[DataProvider('imageFormats')]
    public function test_upload_appends_a_renderable_private_image_and_preserves_existing_blocks(string $extension, string $mime): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $page = $this->page($admin);
        $before = $page->document;
        $image = UploadedFile::fake()->image('example.'.$extension, 12, 8);
        $bytes = file_get_contents($image->getRealPath());
        $this->token($admin);

        $response = $this->uploadImage($this->arguments($page, ['filename' => 'example.'.$extension, 'image_base64' => base64_encode($bytes)]));
        $response->assertJsonPath('result.isError', false);
        $result = json_decode($response->json('result.content.0.text'), true);
        $asset = Asset::sole();

        $this->assertSame(2, $result['revision']);
        $this->assertSame($page->id, $result['page_id']);
        $this->assertSame($mime, $result['asset']['mime']);
        $this->assertSame($before['content'], array_slice($page->fresh()->document['content'], 0, 2));
        $this->assertSame(['id' => $result['block_id'], 'assetId' => $asset->id, 'kind' => 'image', 'title' => 'example.'.$extension], $page->fresh()->document['content'][2]['attrs']);
        $this->assertSame($bytes, Storage::disk('local')->get($asset->path));
        $this->assertDatabaseHas('revisions', ['page_id' => $page->id, 'number' => 2, 'reason' => 'Вставка примера']);
        $this->assertDatabaseCount('publications', 0);
        $this->withoutToken();
        $this->app['auth']->forgetGuards();
        $this->get('/media/'.$asset->id)->assertUnauthorized();
        $this->actingAs($admin)->get('/media/'.$asset->id)->assertOk()->assertHeader('Content-Type', $mime);
        Queue::assertPushed(IndexPage::class, 2);
        Queue::assertNotPushed(TranscribeCall::class);
    }

    public function test_upload_inserts_after_the_observed_block_and_retry_does_not_duplicate_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $page = $this->page($admin);
        $this->token($admin);
        $arguments = $this->arguments($page, ['after_block_id' => 'first-block', 'title' => 'Пример квалификации']);

        $first = $this->uploadImage($arguments)->assertJsonPath('result.isError', false);
        $second = $this->uploadImage($arguments)->assertJsonPath('result.isError', false);

        $this->assertSame($first->json('result.content'), $second->json('result.content'));
        $blocks = $page->fresh()->document['content'];
        $this->assertSame('first-block', $blocks[0]['attrs']['id']);
        $this->assertSame('image', $blocks[1]['attrs']['kind']);
        $this->assertSame('Пример квалификации', $blocks[1]['attrs']['title']);
        $this->assertSame('last-block', $blocks[2]['attrs']['id']);
        $this->assertDatabaseCount('assets', 1);
        $this->assertDatabaseCount('operations', 1);
        $this->assertSame(2, $page->fresh()->revision);
        $this->assertCount(1, Storage::disk('local')->allFiles('assets'));
        Queue::assertPushed(IndexPage::class, 2);
    }

    public function test_reusing_a_request_id_with_changed_content_returns_an_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $page = $this->page($admin);
        $this->token($admin);
        $arguments = $this->arguments($page);
        $this->uploadImage($arguments)->assertJsonPath('result.isError', false);

        $this->uploadImage([...$arguments, 'filename' => 'other.png'])->assertJsonPath('result.isError', true)->assertSee('request_id');

        $this->assertDatabaseCount('assets', 1);
        $this->assertSame(2, $page->fresh()->revision);
    }

    public function test_stale_revision_returns_an_error_without_uploading_a_file(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $page = $this->page($admin);
        $this->token($admin);

        $this->uploadImage($this->arguments($page, ['expected_revision' => 2]))->assertJsonPath('result.isError', true)->assertSee('Документ уже изменён');

        $this->assertUnchanged($page);
    }

    public function test_missing_anchor_returns_an_error_without_uploading_a_file(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $page = $this->page($admin);
        $this->token($admin);

        $this->uploadImage($this->arguments($page, ['after_block_id' => 'missing-block']))->assertJsonPath('result.isError', true)->assertSee('блок верхнего уровня не найден');

        $this->assertUnchanged($page);
    }

    public function test_image_insert_does_not_change_an_existing_publication_snapshot(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $page = $this->page($admin);
        $publication = app(Knowledge::class)->publish($admin, $page, 1, false);
        $publishedRevision = $publication->revision_id;
        $page = $page->fresh();
        $this->token($admin);

        $this->uploadImage($this->arguments($page))->assertJsonPath('result.isError', false);

        $this->assertSame(3, $page->fresh()->revision);
        $this->assertSame($publishedRevision, $publication->fresh()->revision_id);
        $this->get('/p/'.$publication->slug)->assertDontSee('<img', false);
        $this->get('/media/'.Asset::sole()->id.'?publication='.$publication->id)->assertNotFound();
    }

    public static function refusedPermissions(): array
    {
        return ['read-only token' => ['admin', ['kb:read']], 'reader with write scope' => ['reader', ['kb:read', 'kb:write']]];
    }

    #[DataProvider('refusedPermissions')]
    public function test_insufficient_scope_or_role_cannot_upload_an_image(string $role, array $abilities): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $page = $this->page($owner);
        $actor = User::factory()->create(['role' => $role]);
        $this->token($actor, $abilities);

        $this->uploadImage($this->arguments($page))->assertJsonPath('result.isError', true);

        $this->assertUnchanged($page);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $page = $this->page($admin);

        $this->uploadImage($this->arguments($page))->assertUnauthorized();

        $this->assertUnchanged($page);
    }

    public function test_editor_without_page_access_cannot_upload_or_read_the_image(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $page = $this->page($owner, ['visibility' => 'restricted']);
        $editor = User::factory()->create(['role' => 'editor']);
        $this->token($editor);

        $this->uploadImage($this->arguments($page))->assertJsonPath('result.isError', true);

        $this->assertUnchanged($page);
    }

    public function test_archived_page_rejects_an_image_without_creating_files(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $page = $this->page($admin);
        $page->update(['status' => 'archived', 'archived_at' => now()]);
        $this->token($admin);

        $this->uploadImage($this->arguments($page))->assertJsonPath('result.isError', true)->assertSee('архив');

        $this->assertUnchanged($page);
    }

    public static function invalidFiles(): array
    {
        return [
            'malformed base64' => ['example.png', 'not-base64!'],
            'data URL prefix' => ['example.png', 'data:image/png;base64,aGVsbG8='],
            'text disguised as PNG' => ['example.png', base64_encode('plain text')],
            'SVG' => ['example.svg', base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>')],
            'empty decoded file' => ['example.png', '===='],
        ];
    }

    #[DataProvider('invalidFiles')]
    public function test_invalid_image_returns_an_error_without_creating_files(string $filename, string $bytes): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $page = $this->page($admin);
        $this->token($admin);

        $this->uploadImage($this->arguments($page, ['filename' => $filename, 'image_base64' => $bytes]))->assertJsonPath('result.isError', true);

        $this->assertUnchanged($page);
    }

    public function test_filename_extension_must_match_the_image_content(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $page = $this->page($admin);
        $this->token($admin);

        $this->uploadImage($this->arguments($page, ['filename' => 'example.jpg']))->assertJsonPath('result.isError', true)->assertSee('соответствующим расширением');

        $this->assertUnchanged($page);
    }

    public function test_image_over_10_mib_returns_an_error_without_creating_files(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $page = $this->page($admin);
        $this->token($admin);

        $this->uploadImage($this->arguments($page, ['image_base64' => base64_encode(str_repeat('x', 10 * 1024 * 1024 + 1))]))->assertJsonPath('result.isError', true)->assertSee('10 МиБ');

        $this->assertUnchanged($page);
    }

    public function test_windows_paths_are_normalized_to_a_filename(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $page = $this->page($admin);
        $this->token($admin);

        $this->uploadImage($this->arguments($page, ['filename' => 'C:\\screenshots\\example.png']))->assertJsonPath('result.isError', false);

        $this->assertSame('example.png', Asset::sole()->name);
    }

    public function test_failed_document_save_rolls_back_the_asset_and_removes_the_stored_file(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $page = $this->page($admin);
        $page->update(['document' => ['type' => 'doc', 'content' => [['type' => 'unsupported-block']]]]);
        $this->token($admin);

        $this->uploadImage($this->arguments($page))->assertJsonPath('result.isError', true);

        $this->assertUnchanged($page);
    }
}

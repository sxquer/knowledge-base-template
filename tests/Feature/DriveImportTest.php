<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Page;
use App\Models\Publication;
use App\Models\Revision;
use App\Models\User;
use App\Services\Knowledge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DriveImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function rows(): array
    {
        Storage::disk('local')->put('export/template.html', '<script>alert(1)</script>');

        return [
            ['id' => 'folder', 'title' => 'Folder', 'kind' => 'section', 'source_url' => 'https://drive.google.com/drive/folders/folder', 'document' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]]],
            ['id' => 'file', 'title' => 'Template', 'parent' => 'folder', 'source_url' => 'https://drive.google.com/file/d/file/view', 'document' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Folder link', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://drive.google.com/drive/folders/folder']]]]]]]], 'assets' => [['path' => 'template.html', 'name' => 'template.html', 'mime' => 'text/html', 'kind' => 'file', 'sha256' => hash('sha256', '<script>alert(1)</script>')]]],
        ];
    }

    private function manifest(array $rows): string
    {
        Storage::disk('local')->put('export/manifest.json', json_encode($rows));

        return Storage::disk('local')->path('export/manifest.json');
    }

    public function test_import_preserves_hierarchy_links_private_assets_and_replays_without_duplicates(): void
    {
        $path = $this->manifest($this->rows());
        $this->artisan('kb:import-drive', ['manifest' => $path])->assertSuccessful();
        $folder = Page::where('source_key', 'drive:folder')->firstOrFail();
        $file = Page::where('source_key', 'drive:file')->firstOrFail();
        $this->assertSame($folder->id, $file->parent_id);
        $this->assertSame('/app/'.$folder->id, $file->document['content'][0]['content'][0]['marks'][0]['attrs']['href']);
        $asset = Asset::sole();
        $this->assertSame($file->id, $asset->page_id);
        $this->assertSame('<script>alert(1)</script>', Storage::disk('local')->get($asset->path));
        $this->assertSame(0, Publication::count());
        $count = Revision::count();
        $this->artisan('kb:import-drive', ['manifest' => $path])->assertSuccessful();
        $this->assertSame(2, Page::count());
        $this->assertSame(1, Asset::count());
        $this->assertSame($count, Revision::count());
        $this->get('/media/'.$asset->id)->assertUnauthorized();
        $response = $this->actingAs($this->admin)->get('/media/'.$asset->id);
        $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
    }

    public function test_existing_source_is_adopted_without_overwriting_content(): void
    {
        $original = app(Knowledge::class)->save($this->admin, ['title' => 'Edited by owner', 'document' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Owner edits']]]]], 'reason' => 'Owner edit']);
        $original->update(['source_key' => 'local:existing']);
        $rows = $this->rows();
        $rows[1]['reuse_source_key'] = 'local:existing';
        $rows[1]['assets'] = [];
        $this->artisan('kb:import-drive', ['manifest' => $this->manifest($rows)])->assertSuccessful();
        $this->assertSame($original->document, $original->fresh()->document);
        $this->assertSame('Edited by owner', $original->fresh()->title);
        $this->assertSame(2, Page::count());
        $this->assertSame(0, Asset::count());
    }

    public function test_checksum_mismatch_prevents_all_database_writes(): void
    {
        $rows = $this->rows();
        $rows[1]['assets'][0]['sha256'] = str_repeat('0', 64);
        try {
            $this->artisan('kb:import-drive', ['manifest' => $this->manifest($rows)])->run();
            $this->fail('Expected checksum rejection');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('checksum', $e->getMessage());
        }
        $this->assertSame(0, Page::count());
    }

    public function test_changed_source_requires_merge_instead_of_overwriting(): void
    {
        $rows = $this->rows();
        $this->artisan('kb:import-drive', ['manifest' => $this->manifest($rows)])->assertSuccessful();
        $rows[1]['title'] = 'Changed source';
        try {
            $this->artisan('kb:import-drive', ['manifest' => $this->manifest($rows)])->run();
            $this->fail('Expected merge conflict');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('manual merge', $e->getMessage());
        }
        $this->assertSame('Template', Page::where('source_key', 'drive:file')->value('title'));
        $this->assertSame(1, Asset::count());
    }
}

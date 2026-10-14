<?php

namespace Tests\Feature;

use App\Jobs\IndexPage;
use App\Jobs\RunAudit;
use App\Models\Asset;
use App\Models\Audit;
use App\Models\Chunk;
use App\Models\Page;
use App\Models\Publication;
use App\Models\Revision;
use App\Models\Setting;
use App\Models\User;
use App\Models\WidgetDefinition;
use App\Services\Access;
use App\Services\Documents;
use App\Services\Knowledge;
use App\Services\MarkdownImport;
use App\Services\OpenRouter;
use App\Services\Search;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KnowledgeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function doc(string $text = 'Исходное правило', string $id = 'block-one'): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'attrs' => ['id' => $id], 'content' => [['type' => 'text', 'text' => $text]]]]];
    }

    private function page(array $overrides = []): Page
    {
        return app(Knowledge::class)->save($this->admin, [...['title' => 'Правило', 'document' => $this->doc(), 'reason' => 'Создано для теста'], ...$overrides]);
    }

    private function update(Page $p, array $overrides = []): array
    {
        return [...$p->toArray(), 'expected_revision' => $p->revision, 'reason' => 'Уточнение правила', ...$overrides];
    }

    public function test_private_app_requires_login(): void
    {
        $this->get('/app')->assertRedirect('/login');
        $this->getJson('/api/pages')->assertUnauthorized();
    }

    public function test_reader_cannot_write_or_mint_write_token(): void
    {
        $u = User::factory()->create(['role' => 'reader']);
        $this->actingAs($u)->postJson('/api/pages', ['title' => 'X', 'document' => $this->doc(), 'reason' => 'x'])->assertForbidden();
        $this->postJson('/api/tokens', ['name' => 'agent', 'write' => true])->assertForbidden();
    }

    public function test_articles_and_sections_can_be_exported_as_docx(): void
    {
        $section = $this->page(['title' => 'Стратегия', 'kind' => 'section']);
        $child = $this->page(['title' => 'Сегментация', 'parent_id' => $section->id, 'document' => ['type' => 'doc', 'content' => [['type' => 'heading', 'attrs' => ['id' => 'audience', 'level' => 2], 'content' => [['type' => 'text', 'text' => 'Аудитория']]], ['type' => 'paragraph', 'attrs' => ['id' => 'details'], 'content' => [['type' => 'text', 'text' => 'Описание сегментации']]]]]]);
        $this->page(['title' => 'Интервью', 'parent_id' => $child->id]);

        $response = $this->actingAs($this->admin)->get('/api/pages/'.$section->id.'/export/docx');
        $response->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $content = $response->streamedContent();
        $this->assertStringStartsWith('PK', $content);

        $file = tempnam(sys_get_temp_dir(), 'marketingbro-export-');
        file_put_contents($file, $content);
        $zip = new \ZipArchive;
        $zip->open($file);
        $document = $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($file);

        $this->assertStringContainsString('Оглавление', $document);
        $this->assertStringContainsString('Стратегия', $document);
        $this->assertStringContainsString('Сегментация', $document);
        $this->assertStringContainsString('Интервью', $document);
        $this->assertStringContainsString('Описание сегментации', $document);

        $articleResponse = $this->actingAs($this->admin)->get('/api/pages/'.$child->id.'/export/docx')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $articleResponse->streamedContent();
    }

    public function test_pdf_export_reports_a_clear_error_when_libreoffice_is_unavailable(): void
    {
        config(['services.exports.soffice_binary' => 'marketingbro-missing-soffice']);
        $page = $this->page();

        $this->actingAs($this->admin)->get('/api/pages/'.$page->id.'/export/pdf')->assertStatus(503)->assertSee('LibreOffice');
    }

    public function test_docx_export_preserves_rich_text_tables_links_and_images(): void
    {
        Storage::fake('local');
        $page = $this->page(['title' => 'Оформленная статья']);
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9J7nUAAAAASUVORK5CYII=');
        Storage::disk('local')->put('assets/export-image.png', $image);
        $asset = Asset::create(['page_id' => $page->id, 'user_id' => $this->admin->id, 'disk' => 'local', 'path' => 'assets/export-image.png', 'name' => 'scheme.png', 'mime' => 'image/png', 'size' => strlen($image)]);
        $document = ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Ключевая идея']]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Важный '],
                ['type' => 'text', 'text' => 'акцент', 'marks' => [['type' => 'bold']]],
                ['type' => 'text', 'text' => ' и '],
                ['type' => 'text', 'text' => 'ссылка', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com']]]],
            ]],
            ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Пункт списка']]]]]]],
            ['type' => 'table', 'content' => [
                ['type' => 'tableRow', 'content' => [['type' => 'tableHeader', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Показатель']]]]], ['type' => 'tableHeader', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Значение']]]]]]],
                ['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Конверсия']]]]], ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '12%']]]]]]],
            ]],
            ['type' => 'image', 'attrs' => ['assetId' => $asset->id, 'alt' => 'Схема воронки']],
        ]];
        app(Knowledge::class)->save($this->admin, $this->update($page, ['document' => $document]), $page);

        $content = $this->actingAs($this->admin)->get('/api/pages/'.$page->id.'/export/docx')->assertOk()->streamedContent();
        $file = tempnam(sys_get_temp_dir(), 'marketingbro-rich-export-');
        file_put_contents($file, $content);
        $zip = new \ZipArchive;
        $zip->open($file);
        $xml = $zip->getFromName('word/document.xml');
        $relationships = $zip->getFromName('word/_rels/document.xml.rels');
        $this->assertNotFalse($zip->getFromName('word/media/image1.png'));
        $zip->close();
        unlink($file);

        $this->assertStringContainsString('<w:b/>', $xml);
        $this->assertStringContainsString('<w:hyperlink r:id=', $xml);
        $this->assertStringContainsString('<w:drawing>', $xml);
        $this->assertStringContainsString('w:fill="EAF0FF"', $xml);
        $this->assertStringContainsString('https://example.com', $relationships);
    }

    public function test_update_is_versioned_and_stale_writes_fail(): void
    {
        $p = $this->page();
        $data = $this->update($p, ['document' => $this->doc('Новое правило')]);
        $this->actingAs($this->admin)->putJson('/api/pages/'.$p->id, $data)->assertOk()->assertJsonPath('revision', 2);
        $this->putJson('/api/pages/'.$p->id, $data)->assertConflict();
        $this->assertSame(2, Revision::where('page_id', $p->id)->count());
        $this->assertSame('Исходное правило', Revision::where('page_id', $p->id)->first()->snapshot['document']['content'][0]['content'][0]['text']);
    }

    public function test_idempotency_replays_once_and_rejects_changed_payload(): void
    {
        $d = ['title' => 'Only once', 'document' => $this->doc(), 'reason' => 'Create'];
        $this->actingAs($this->admin)->withHeader('Idempotency-Key', 'same-operation-id')->postJson('/api/pages', $d)->assertOk();
        $this->postJson('/api/pages', $d)->assertOk();
        $this->assertSame(1, Page::count());
        $this->postJson('/api/pages', [...$d, 'title' => 'Different'])->assertConflict();
    }

    public function test_public_snapshot_does_not_follow_private_edits(): void
    {
        $p = $this->page();
        $this->actingAs($this->admin)->postJson('/api/pages/'.$p->id.'/publish', ['expected_revision' => 1, 'indexed' => false])->assertOk();
        $pub = Publication::first();
        $p->refresh();
        $this->putJson('/api/pages/'.$p->id, $this->update($p, ['document' => $this->doc('PRIVATE NEW TEXT')]))->assertOk();
        $this->get('/p/'.$pub->id)->assertOk()->assertDontSee('PRIVATE NEW TEXT')->assertSee('Исходное правило', false)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_unpublish_revokes_url(): void
    {
        $p = $this->page();
        $pub = app(Knowledge::class)->publish($this->admin, $p, 1, false);
        $this->actingAs($this->admin)->deleteJson('/api/pages/'.$p->id.'/publish', ['expected_revision' => 2])->assertOk();
        $this->get('/p/'.$pub->id)->assertNotFound();
    }

    public function test_cycle_is_rejected(): void
    {
        $a = $this->page(['title' => 'A']);
        $b = $this->page(['title' => 'B', 'parent_id' => $a->id]);
        $this->actingAs($this->admin)->putJson('/api/pages/'.$a->id, $this->update($a, ['parent_id' => $b->id]))->assertUnprocessable();
    }

    public function test_page_can_move_between_sections_and_back_to_root(): void
    {
        $first = $this->page(['title' => 'First section', 'kind' => 'section']);
        $second = $this->page(['title' => 'Second section', 'kind' => 'section']);
        $child = $this->page(['title' => 'Nested page', 'parent_id' => $first->id]);

        $this->actingAs($this->admin)->putJson('/api/pages/'.$child->id, $this->update($child, ['parent_id' => $second->id]))->assertOk()->assertJsonPath('parent_id', $second->id);

        $child->refresh();
        $this->putJson('/api/pages/'.$child->id, $this->update($child, ['parent_id' => null]))->assertOk()->assertJsonPath('parent_id', null);
        $this->assertSame(3, $child->fresh()->revision);
    }

    public function test_restricted_ancestor_is_hidden_in_tree_search_history(): void
    {
        $parent = $this->page(['visibility' => 'restricted']);
        $child = $this->page(['title' => 'Secret topic', 'parent_id' => $parent->id, 'document' => $this->doc('secretphrase')]);
        $reader = User::factory()->create(['role' => 'reader']);
        $this->actingAs($reader)->getJson('/api/pages')->assertExactJson([]);
        $this->getJson('/api/pages/'.$child->id)->assertNotFound();
        $this->getJson('/api/pages/'.$child->id.'/revisions')->assertNotFound();
        $this->getJson('/api/search?q=secretphrase')->assertJsonPath('results', []);
        $this->getJson('/api/changes?since=2020-01-01')->assertExactJson([]);
    }

    public function test_restore_preserves_history(): void
    {
        $p = $this->page();
        $first = $p->revisions()->first();
        app(Knowledge::class)->save($this->admin, $this->update($p, ['title' => 'New title']), $p);
        $this->actingAs($this->admin)->postJson('/api/pages/'.$p->id.'/restore/'.$first->id, ['expected_revision' => 2])->assertOk()->assertJsonPath('title', 'Правило')->assertJsonPath('revision', 3);
    }

    public function test_archive_removes_publication_and_search_results(): void
    {
        $p = $this->page();
        app(Knowledge::class)->publish($this->admin, $p, 1, false);
        $p->refresh();
        $this->actingAs($this->admin)->putJson('/api/pages/'.$p->id, $this->update($p, ['status' => 'archived']))->assertOk();
        $this->assertSame(0, Publication::count());
        $this->assertSame(0, Chunk::count());
        $this->getJson('/api/changes?since=2020-01-01')->assertJsonFragment(['action' => 'archived']);
    }

    public function test_changes_include_exact_before_after(): void
    {
        $p = $this->page();
        app(Knowledge::class)->save($this->admin, $this->update($p, ['document' => $this->doc('Новый следующий шаг')]), $p);
        $r = $this->actingAs($this->admin)->getJson('/api/changes?since=2020-01-01')->assertOk();
        $r->assertJsonPath('1.diff.0.before', 'Исходное правило')->assertJsonPath('1.diff.0.after', 'Новый следующий шаг');
    }

    public function test_widgets_are_validated_and_searchable(): void
    {
        WidgetDefinition::create(['key' => 'call_review', 'name' => 'Разбор звонка', 'fields' => [['key' => 'result', 'label' => 'Результат', 'type' => 'text', 'required' => true]], 'view' => 'fields']);
        $doc = ['type' => 'doc', 'content' => [['type' => 'kbWidget', 'attrs' => ['definition' => 'call_review', 'schemaVersion' => 1, 'data' => ['result' => 'уникальное возражение']]]]];
        $p = $this->page(['document' => $doc]);
        $this->actingAs($this->admin)->getJson('/api/search?q='.urlencode('возражение'))->assertJsonPath('results.0.id', $p->id);
        $doc['content'][0]['attrs']['data'] = [];
        $this->putJson('/api/pages/'.$p->id, $this->update($p, ['document' => $doc]))->assertUnprocessable();
    }

    public function test_bad_markup_and_unsafe_links_are_not_persisted(): void
    {
        $p = $this->page();
        $this->actingAs($this->admin)->putJson('/api/pages/'.$p->id, $this->update($p, ['document' => ['type' => 'doc', 'content' => [['type' => 'script', 'text' => 'alert(1)']]]]))->assertUnprocessable();
        $d = $this->doc();
        $d['content'][0]['content'][0]['marks'] = [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]];
        $this->putJson('/api/pages/'.$p->id, $this->update($p, ['document' => $d]))->assertOk();
        $this->assertArrayNotHasKey('marks', $p->fresh()->document['content'][0]['content'][0]);
    }

    public function test_mcp_is_authenticated_and_enforces_token_abilities(): void
    {
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
        $reader = User::factory()->create(['role' => 'reader']);
        $token = $reader->createToken('read', ['kb:read'])->plainTextToken;
        $this->withToken($token)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertOk()->assertSee('kb_read');
        $this->withToken($token)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'kb_write', 'arguments' => ['action' => 'create', 'request_id' => 'read-only-blocked', 'payload_json' => json_encode(['title' => 'Bad', 'document' => $this->doc(), 'reason' => 'no'])]]])->assertOk();
        $this->assertSame(0, Page::count());
    }

    public function test_mcp_write_updates_same_version_model(): void
    {
        $token = $this->admin->createToken('write', ['kb:read', 'kb:write'])->plainTextToken;
        $this->withToken($token)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'kb_write', 'arguments' => ['action' => 'create', 'request_id' => 'write-operation-one', 'payload_json' => json_encode(['title' => 'From agent', 'document' => $this->doc(), 'reason' => 'Agent created'])]]])->assertOk()->assertSee('From agent');
        $this->assertSame(1, Page::count());
        $this->assertSame(1, Revision::count());
    }

    public function test_secret_is_encrypted_and_not_returned(): void
    {
        $this->actingAs($this->admin)->putJson('/api/settings', ['key' => 'test-openrouter-secret', 'chat_model' => 'test/chat', 'embedding_model' => 'test/embed'])->assertOk();
        $this->assertStringNotContainsString('test-openrouter-secret', Setting::first()->getRawOriginal('value'));
        $this->getJson('/api/settings')->assertDontSee('test-openrouter-secret');
    }

    public function test_openrouter_indexing_and_hybrid_search(): void
    {
        Setting::create(['key' => 'openrouter', 'value' => ['key' => 'test-key', 'chat_model' => 'test/chat', 'embedding_model' => 'test/embed']]);
        Http::fake(['openrouter.ai/api/v1/embeddings' => Http::response(['data' => [['index' => 0, 'embedding' => [1.0, 0.0, 0.0]]]])]);
        $p = $this->page();
        (new IndexPage($p->id, 1))->handle(app(OpenRouter::class));
        $r = app(Search::class)->run($this->admin, 'смысловой запрос');
        $this->assertSame('hybrid', $r['mode']);
        $this->assertSame($p->id, $r['results'][0]['id']);
    }

    public function test_audit_requires_provider_instead_of_faking_result(): void
    {
        $this->actingAs($this->admin)->postJson('/api/audits', ['topic' => 'Зарплата'])->assertUnprocessable();
        $this->assertSame(0, Audit::count());
    }

    public function test_failed_multi_page_audit_apply_is_atomic(): void
    {
        $a = $this->page(['title' => 'A']);
        $b = $this->page(['title' => 'B']);
        $audit = Audit::create(['user_id' => $this->admin->id, 'topic' => 'Test', 'status' => 'complete', 'report' => ['coverage' => ['pages' => [$a->id, $b->id]], 'applied' => [], 'conflicts' => [['options' => [['label' => 'Chosen rule', 'patches' => [['page_id' => $a->id, 'block_id' => 'block-one', 'expected_revision' => 1, 'after' => 'Patched'], ['page_id' => $b->id, 'block_id' => 'block-one', 'expected_revision' => 99, 'after' => 'Patched']]]]]]]]);
        $this->actingAs($this->admin)->postJson('/api/audits/'.$audit->id.'/apply', ['conflict' => 0, 'option' => 0])->assertConflict();
        $this->assertSame(1, $a->fresh()->revision);
        $this->assertSame(1, $b->fresh()->revision);
    }

    public function test_public_media_checks_published_document_membership(): void
    {
        Storage::fake('local');
        $p = $this->page();
        Storage::disk('local')->put('assets/test.txt', 'private bytes');
        $asset = Asset::create(['page_id' => $p->id, 'user_id' => $this->admin->id, 'path' => 'assets/test.txt', 'name' => 'test.txt', 'mime' => 'text/plain', 'size' => 13]);
        $pub = app(Knowledge::class)->publish($this->admin, $p, 1, false);
        $this->get('/media/'.$asset->id.'?publication='.$pub->id)->assertNotFound();
        $this->get('/media/'.$asset->id)->assertUnauthorized();
    }

    public function test_inline_spaces_survive_http_roundtrip(): void
    {
        $d = $this->doc();
        $d['content'][0]['content'] = [['type' => 'text', 'text' => 'До '], ['type' => 'text', 'text' => 'жирного', 'marks' => [['type' => 'bold']]], ['type' => 'text', 'text' => ' после']];
        $r = $this->actingAs($this->admin)->postJson('/api/pages', ['title' => 'Spacing', 'document' => $d, 'reason' => 'Test'])->assertOk();
        $r->assertJsonPath('document.content.0.content.0.text', 'До ')->assertJsonPath('document.content.0.content.2.text', ' после');
    }

    public function test_markdown_import_keeps_lists_and_formatting(): void
    {
        $d = app(MarkdownImport::class)->document("## Заголовок\n\nДо **жирного** после.\n\n1. Первый\n2. Второй");
        $this->assertSame('heading', $d['content'][0]['type']);
        $this->assertSame('orderedList', $d['content'][2]['type']);
        $this->assertSame('До жирного после.', app(Documents::class)->text($d['content'][1]));
        $this->page(['document' => $d]);
    }

    public function test_section_archive_does_not_orphan_active_children(): void
    {
        $a = $this->page(['kind' => 'section']);
        $this->page(['parent_id' => $a->id]);
        $this->actingAs($this->admin)->putJson('/api/pages/'.$a->id, $this->update($a, ['status' => 'archived']))->assertUnprocessable();
        $this->assertNull($a->fresh()->archived_at);
    }

    public function test_import_manifest_is_idempotent_and_flags_changed_sources(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'kb-manifest-');
        try {
            $rows = [['source_key' => 'drive:test-section', 'title' => 'Imported', 'markdown' => 'Раздел', 'kind' => 'section'], ['source_key' => 'drive:test-doc', 'parent_source_key' => 'drive:test-section', 'title' => 'Document', 'markdown' => 'Текст']];
            file_put_contents($file, json_encode($rows));
            $this->artisan('kb:import', ['manifest' => $file])->assertExitCode(0);
            $this->artisan('kb:import', ['manifest' => $file])->assertExitCode(0);
            $this->assertSame(2, Page::count());
            $rows[1]['markdown'] = 'Changed source';
            file_put_contents($file, json_encode($rows));
            $this->artisan('kb:import', ['manifest' => $file])->assertExitCode(1);
            $this->assertSame(2, Page::count());
            $this->assertSame(1, Page::where('source_key', 'drive:test-doc')->value('revision'));
        } finally {
            unlink($file);
        }
    }

    public function test_audit_checks_citations_and_applies_chosen_changes(): void
    {
        $a = $this->page(['title' => 'Правило A', 'document' => $this->doc('Оклад 50 условных единиц', 'a-block')]);
        $b = $this->page(['title' => 'Правило B', 'document' => $this->doc('Оклад 70 условных единиц', 'b-block')]);
        Setting::create(['key' => 'openrouter', 'value' => ['key' => 'test-key', 'chat_model' => 'test/chat', 'embedding_model' => 'test/embed']]);
        $result = ['summary' => 'Найдены варианты', 'conflicts' => [['title' => 'Разные суммы', 'explanation' => 'Нужно уточнить период', 'occurrences' => [['page_id' => $a->id, 'block_id' => 'a-block', 'quote' => 'Оклад 50'], ['page_id' => $b->id, 'block_id' => 'b-block', 'quote' => 'Оклад 70']], 'options' => [['label' => 'Оставить 70', 'patches' => [['page_id' => $a->id, 'block_id' => 'a-block', 'replacement_text' => 'Оклад 70 условных единиц']]]]]]];
        Http::fake(['openrouter.ai/api/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode($result)]]]])]);
        $audit = Audit::create(['user_id' => $this->admin->id, 'topic' => 'Оклад']);
        (new RunAudit($audit->id))->handle(app(Access::class), app(OpenRouter::class), app(Documents::class));
        $this->assertSame('complete', $audit->fresh()->status);
        $this->assertCount(1, $audit->fresh()->report['conflicts']);
        $this->assertSame(1, $a->fresh()->revision);
        $this->actingAs($this->admin)->postJson('/api/audits/'.$audit->id.'/apply', ['conflict' => 0, 'option' => 0])->assertOk();
        $this->assertSame('Оклад 70 условных единиц', app(Documents::class)->text($a->fresh()->document));
        $this->postJson('/api/audits/'.$audit->id.'/apply', ['conflict' => 0, 'option' => 0])->assertConflict();
    }

    public function test_change_range_respects_timezone_and_reports_media(): void
    {
        $this->travelTo(Carbon::parse('2026-09-16T15:00:00Z'));
        $p = $this->page();
        $asset = Asset::create(['page_id' => $p->id, 'user_id' => $this->admin->id, 'name' => 'instruction.mp4', 'mime' => 'video/mp4', 'path' => 'test.mp4', 'disk' => 'local', 'size' => 10]);
        app(Knowledge::class)->save($this->admin, $this->update($p, ['document' => ['type' => 'doc', 'content' => [['type' => 'media', 'attrs' => ['id' => 'video-block', 'assetId' => $asset->id, 'kind' => 'video']]]]]), $p);
        $included = app(Knowledge::class)->changes($this->admin, '2026-09-17T00:00:00+10:00');
        $this->assertCount(2, $included);
        $this->assertStringContainsString('Видео: instruction.mp4', json_encode($included, JSON_UNESCAPED_UNICODE));
        $this->assertSame([], app(Knowledge::class)->changes($this->admin, '2026-09-17T02:00:00+10:00'));
    }

    public function test_first_local_owner_initializes_base_and_setup_cannot_repeat(): void
    {
        User::query()->delete();
        app()->instance('env', 'local');
        $credentials = ['name' => 'Test owner', 'email' => 'owner@example.test', 'password' => 'Test-only-password-123'];
        $this->withSession(['_token' => 'setup-test-token'])->post('/setup', [...$credentials, '_token' => 'setup-test-token'])->assertRedirect('/app');
        $this->assertAuthenticated();
        $this->assertSame('admin', User::first()->role);
        $this->assertSame(10, Page::where('kind', 'section')->count());
        $this->assertSame(3, WidgetDefinition::count());
        $this->post('/setup', [...$credentials, '_token' => session()->token()])->assertForbidden();
        $this->assertSame(1, User::count());
    }

    public function test_reader_can_get_existing_link_but_cannot_publish_change_slug_or_unpublish(): void
    {
        $p = $this->page(['title' => 'Правила работы']);
        $reader = User::factory()->create(['role' => 'reader']);
        $this->actingAs($reader)->postJson('/api/pages/'.$p->id.'/publish', ['expected_revision' => 1, 'indexed' => false])->assertForbidden();
        $this->assertSame(0, Publication::count());
        $pub = app(Knowledge::class)->publish($this->admin, $p, 1, false);
        $this->getJson('/api/pages/'.$p->id)->assertOk()->assertJsonPath('publication.slug', $pub->slug);
        $this->postJson('/api/pages/'.$p->id.'/publish', ['expected_revision' => 2, 'indexed' => true, 'slug' => 'other-address'])->assertForbidden();
        $this->deleteJson('/api/pages/'.$p->id.'/publish', ['expected_revision' => 2])->assertForbidden();
        $this->assertSame($pub->slug, $pub->fresh()->slug);
    }

    public function test_public_slug_defaults_to_title_handles_duplicates_and_can_be_edited(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $a = $this->page(['title' => 'Правила работы']);
        $b = $this->page(['title' => 'Правила работы']);
        $this->actingAs($editor)->postJson('/api/pages/'.$a->id.'/publish', ['expected_revision' => 1, 'indexed' => false])->assertOk()->assertJsonPath('publication.slug', 'pravila-raboty');
        $this->postJson('/api/pages/'.$b->id.'/publish', ['expected_revision' => 1, 'indexed' => false])->assertOk()->assertJsonPath('publication.slug', 'pravila-raboty-2');
        $this->get('/p/pravila-raboty')->assertOk()->assertSee('Правила работы', false);
        $this->postJson('/api/pages/'.$b->id.'/publish', ['expected_revision' => 2, 'indexed' => false, 'slug' => 'pravila-raboty'])->assertUnprocessable();
        $this->postJson('/api/pages/'.$b->id.'/publish', ['expected_revision' => 2, 'indexed' => false, 'slug' => '../bad'])->assertUnprocessable();
        $this->postJson('/api/pages/'.$b->id.'/publish', ['expected_revision' => 2, 'indexed' => false, 'slug' => 'team-guide'])->assertOk();
        $this->get('/p/team-guide')->assertOk();
        $this->get('/p/pravila-raboty-2')->assertNotFound();
        $this->deleteJson('/api/pages/'.$b->id.'/publish', ['expected_revision' => 3])->assertOk();
        $this->get('/p/team-guide')->assertNotFound();
    }
}

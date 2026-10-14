<?php

namespace Tests\Feature;

use App\Models\Chunk;
use App\Models\PasswordEntry;
use App\Models\Revision;
use App\Models\User;
use App\Services\Knowledge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PasswordVaultTest extends TestCase
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

    private function entry(array $overrides = []): PasswordEntry
    {
        return PasswordEntry::factory()->create([...['created_by' => $this->admin->id], ...$overrides]);
    }

    private function passwordDocument(PasswordEntry $entry): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'passwordReference', 'attrs' => ['passwordEntryId' => $entry->id]]]];
    }

    public function test_admin_creates_an_encrypted_entry_and_granted_user_reveals_it_only_on_post(): void
    {
        $reader = User::factory()->create(['role' => 'reader']);
        $secret = 'test-only-password-value';
        $username = 'test-only-login@example.test';

        $this->actingAs($this->admin)->postJson('/api/passwords', [
            'title' => 'Тестовый кабинет',
            'username' => $username,
            'password' => $secret,
            'url' => 'https://example.test/login',
            'notes' => 'Только для теста',
            'user_ids' => [$reader->id],
        ])->assertOk()->assertJsonPath('title', 'Тестовый кабинет')->assertDontSee($secret)->assertDontSee($username);

        $entry = PasswordEntry::sole();
        $this->assertStringNotContainsString($secret, $entry->getRawOriginal('secret'));
        $this->assertStringNotContainsString($username, $entry->getRawOriginal('username'));
        $this->actingAs($reader)->getJson('/api/passwords')->assertOk()->assertJsonPath('entries.0.id', $entry->id)->assertDontSee($secret)->assertDontSee($username);
        $this->getJson('/api/passwords/'.$entry->id)->assertMethodNotAllowed();
        $this->postJson('/api/passwords/'.$entry->id.'/reveal')->assertOk()->assertJsonPath('password', $secret)->assertJsonPath('username', $username)->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_ungranted_users_receive_not_found_and_non_admins_cannot_manage_entries(): void
    {
        $entry = $this->entry();
        $reader = User::factory()->create(['role' => 'reader']);
        $editor = User::factory()->create(['role' => 'editor']);

        $this->actingAs($reader)->postJson('/api/passwords/'.$entry->id.'/reveal')->assertNotFound();
        $this->actingAs($editor)->postJson('/api/passwords', ['title' => 'Нельзя', 'password' => 'test-only', 'user_ids' => []])->assertForbidden();
        $this->putJson('/api/passwords/'.$entry->id, ['title' => 'Нельзя', 'user_ids' => []])->assertForbidden();
    }

    public function test_reference_requires_access_and_never_enters_search_history_mcp_or_public_page(): void
    {
        $secret = 'test-only-hidden-password';
        $username = 'test-only-hidden-login';
        $entry = $this->entry(['secret' => $secret, 'username' => $username]);
        $editor = User::factory()->create(['role' => 'editor']);
        $page = app(Knowledge::class)->save($this->admin, [
            'title' => 'Инструкция',
            'document' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Обычный текст']]]]],
            'reason' => 'Создано для теста',
        ]);

        $this->actingAs($editor)->putJson('/api/pages/'.$page->id, [...$page->toArray(), 'expected_revision' => $page->revision, 'document' => $this->passwordDocument($entry), 'reason' => 'Попытка добавить доступ'])->assertUnprocessable();

        $page = app(Knowledge::class)->save($this->admin, [...$page->toArray(), 'expected_revision' => $page->revision, 'document' => $this->passwordDocument($entry), 'reason' => 'Добавлен виджет доступа'], $page);
        $publication = app(Knowledge::class)->publish($this->admin, $page, $page->revision, false);

        $this->assertStringNotContainsString($secret, json_encode(Revision::all()->toArray()));
        $this->assertStringNotContainsString($username, json_encode(Revision::all()->toArray()));
        $this->assertSame(0, Chunk::where('text', 'like', '%'.$secret.'%')->count());
        $this->actingAs($this->admin)->getJson('/api/search?q='.$secret)->assertOk()->assertJsonPath('results', []);
        $this->get('/p/'.$publication->slug)->assertOk()->assertDontSee('Данные доступа')->assertDontSee($secret)->assertDontSee($username);

        $token = $this->admin->createToken('vault-read-test', ['kb:read'])->plainTextToken;
        $this->withToken($token)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'kb_read', 'arguments' => ['mode' => 'page', 'page_id' => $page->id]]])->assertOk()->assertDontSee($secret)->assertDontSee($username);
    }

    public function test_revoking_access_hides_the_same_widget_on_the_next_request(): void
    {
        $reader = User::factory()->create(['role' => 'reader']);
        $entry = $this->entry();
        $entry->users()->attach($reader);

        $this->actingAs($reader)->postJson('/api/passwords/'.$entry->id.'/reveal')->assertOk();
        $entry->users()->detach($reader);
        $this->postJson('/api/passwords/'.$entry->id.'/reveal')->assertNotFound();
    }
}

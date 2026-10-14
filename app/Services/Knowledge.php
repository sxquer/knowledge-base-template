<?php

namespace App\Services;

use App\Jobs\IndexPage;
use App\Models\Chunk;
use App\Models\Operation;
use App\Models\Page;
use App\Models\Publication;
use App\Models\Revision;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class Knowledge
{
    public function __construct(public Access $access, public Documents $documents) {}

    public function present(Page $page): array
    {
        return [...$page->toArray(), 'publication' => Publication::where('page_id', $page->id)->first(), 'suggested_slug' => $this->suggestSlug($page)];
    }

    public function snapshotFor(User $user, array $snapshot): array
    {
        if (! $user->canViewCalls() && isset($snapshot['document'])) {
            $snapshot['document'] = $this->documents->redactCalls($snapshot['document']);
        }

        return $snapshot;
    }

    public function save(User $user, array $input, ?Page $page = null): Page
    {
        abort_unless($user->canEdit(), 403);
        if ($page) {
            $this->access->requireEdit($user, $page);
        }
        foreach (['subtitle', 'area'] as $field) {
            $input[$field] ??= '';
        }
        $input['sources'] ??= [];
        $data = Validator::make($input, [
            'title' => ['required', 'string', 'max:220'], 'subtitle' => ['sometimes', 'string', 'max:500'], 'kind' => ['sometimes', Rule::in(['article', 'section'])],
            'parent_id' => ['nullable', 'uuid', 'exists:pages,id'], 'document' => ['required', 'array'], 'status' => ['sometimes', Rule::in(['draft', 'review', 'current', 'archived'])],
            'visibility' => ['sometimes', Rule::in(['team', 'restricted'])], 'owner_label' => ['sometimes', 'string', 'max:180'], 'area' => ['sometimes', 'string', 'max:180'],
            'sources' => ['sometimes', 'array', 'max:30'], 'sources.*.url' => ['nullable', 'url:http,https', 'max:2000'], 'sources.*.title' => ['required', 'string', 'max:300'],
            'sources.*.checked_at' => ['nullable', 'date'], 'sources.*.author' => ['nullable', 'string', 'max:180'], 'sources.*.published_at' => ['nullable', 'date'],
            'expected_revision' => [$page ? 'required' : 'nullable', 'integer'], 'reason' => ['required', 'string', 'max:500'],
        ])->validate();

        return DB::transaction(function () use ($user, $page, $data) {
            DB::table('workspace_locks')->where('id', 1)->lockForUpdate()->first();
            if ($page) {
                $page = Page::whereKey($page->id)->lockForUpdate()->firstOrFail();
                abort_if($page->revision !== $data['expected_revision'], 409, 'Документ уже изменён. Откройте новую версию и согласуйте правки.');
            }
            $parent = $data['parent_id'] ?? null;
            if ($parent) {
                $target = Page::findOrFail($parent);
                $this->access->requireEdit($user, $target);
                abort_if($target->archived_at, 422, 'Нельзя добавлять в архив.');
                $seen = [];
                while ($target) {
                    abort_if($target->id === $page?->id || isset($seen[$target->id]), 422, 'Нельзя вложить страницу в саму себя.');
                    $seen[$target->id] = true;
                    $target = $target->parent_id ? Page::find($target->parent_id) : null;
                }
            }
            $data['document'] = $this->documents->validate($data['document'], $page?->id, $user);
            $reason = $data['reason'];
            unset($data['reason'],$data['expected_revision']);
            $isNew = ! $page;
            $page ??= new Page(['created_by' => $user->id, 'revision' => 0]);
            abort_if(($data['status'] ?? '') === 'archived' && $page->exists && Page::where('parent_id', $page->id)->whereNull('archived_at')->exists(), 422, 'Сначала перенесите или архивируйте вложенные страницы.');
            $page->fill($data);
            $page->revision++;
            $page->archived_at = $page->status === 'archived' ? now() : null;
            $page->save();
            $page->refresh();
            $this->record($page, $user, $isNew ? 'created' : ($page->archived_at ? 'archived' : 'updated'), $reason);
            if ($page->archived_at) {
                Publication::where('page_id', $page->id)->delete();
            }
            $this->indexText($page);
            IndexPage::dispatch($page->id, $page->revision)->afterCommit();

            return $page->fresh();
        });
    }

    public function record(Page $page, User $user, string $action, string $reason): Revision
    {
        return Revision::create(['page_id' => $page->id, 'number' => $page->revision, 'actor_id' => $user->id, 'action' => $action, 'reason' => $reason, 'snapshot' => [...$page->only(['id', 'parent_id', 'title', 'subtitle', 'kind', 'status', 'visibility', 'owner_label', 'area', 'sources', 'revision']), 'document' => $this->documents->snapshot($page->document)], 'created_at' => now()]);
    }

    public function indexText(Page $page): void
    {
        Chunk::where('page_id', $page->id)->delete();
        if ($page->archived_at) {
            return;
        }
        foreach ($page->document['content'] ?? [] as $node) {
            $text = trim($this->documents->text($node));
            if ($text === '') {
                continue;
            }
            foreach (mb_str_split($text, 2400) as $part) {
                Chunk::create(['page_id' => $page->id, 'revision' => $page->revision, 'block_id' => $node['attrs']['id'] ?? 'root', 'text' => $page->title."\n".$part]);
            }
        }
    }

    public function publish(User $user, Page $page, int $expected, bool $indexed, ?string $slug = null): Publication
    {
        $this->access->requireEdit($user, $page);

        return DB::transaction(function () use ($user, $page, $expected, $indexed, $slug) {
            DB::table('workspace_locks')->where('id', 1)->lockForUpdate()->first();
            $page = Page::whereKey($page->id)->lockForUpdate()->firstOrFail();
            abort_if($page->revision !== $expected, 409, 'Версия изменилась. Повторите предпросмотр публикации.');
            abort_if($page->archived_at, 422, 'Архив нельзя публиковать.');
            $existing = Publication::where('page_id', $page->id)->first();
            $slug = $slug ?? $existing?->slug ?? $this->suggestSlug($page);
            Validator::make(['slug' => $slug], ['slug' => ['required', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('publications', 'slug')->ignore($existing?->id)]], ['slug.unique' => 'Этот адрес уже занят. Выберите другой.', 'slug.regex' => 'Используйте латинские буквы, цифры и дефисы.'])->validate();
            abort_if(Str::isUuid($slug), 422, 'Выберите адрес из слов.');
            // Publication is a distinct snapshot; private edits never change it.
            $page->revision++;
            $page->save();
            $revision = $this->record($page, $user, 'published', $indexed ? 'Публичная публикация' : 'Публикация по ссылке');

            return Publication::updateOrCreate(['page_id' => $page->id], ['revision_id' => $revision->id, 'indexed' => $indexed, 'slug' => $slug]);
        });
    }

    public function suggestSlug(Page $page): string
    {
        $base = rtrim(substr(Str::slug($page->title, '-', 'ru'), 0, 160), '-') ?: 'stranitsa';
        if (Str::isUuid($base)) {
            $base = 'stranitsa-'.$base;
        }
        $slug = $base;
        $suffix = 2;
        while (Publication::where('slug', $slug)->where('page_id', '!=', $page->id)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    public function unpublish(User $user, Page $page, int $expected): void
    {
        $this->access->requireEdit($user, $page);
        DB::transaction(function () use ($user, $page, $expected) {
            DB::table('workspace_locks')->where('id', 1)->lockForUpdate()->first();
            $page = Page::whereKey($page->id)->lockForUpdate()->firstOrFail();
            abort_if($page->revision !== $expected, 409, 'Версия изменилась.');
            Publication::where('page_id', $page->id)->delete();
            $page->revision++;
            $page->save();
            $this->record($page, $user, 'unpublished', 'Публикация снята');
        });
    }

    public function operation(User $user, string $id, array $payload, callable $run): array
    {
        abort_if(strlen($id) > 100 || strlen($id) < 8, 422, 'Нужен request_id длиной 8–100 символов.');

        return DB::transaction(function () use ($user, $id, $payload, $run) {
            DB::table('workspace_locks')->where('id', 1)->lockForUpdate()->first();
            // Serialize mutations per actor, including first-time operation inserts.
            // The workspace lock also serializes first-time idempotency records.
            $hash = hash('sha256', json_encode($payload));
            $op = Operation::where('actor_id', $user->id)->where('request_id', $id)->first();
            if ($op) {
                abort_if($op->fingerprint !== $hash, 409, 'request_id уже использован с другим содержимым.');

                return $op->result;
            }
            $result = $run();
            Operation::create(['actor_id' => $user->id, 'request_id' => $id, 'fingerprint' => $hash, 'result' => $result]);

            return $result;
        });
    }

    public function changes(User $user, string $since, ?string $until = null): array
    {
        $since = Carbon::parse($since)->utc()->format('Y-m-d H:i:s');
        $until = $until ? Carbon::parse($until)->utc()->format('Y-m-d H:i:s') : null;
        $ids = $this->access->pages($user, true)->pluck('id');
        $revs = Revision::whereIn('page_id', $ids)->where('created_at', '>=', $since)->when($until, fn ($q) => $q->where('created_at', '<=', $until))->orderBy('created_at')->orderBy('id')->get();

        return $revs->map(function ($r) use ($user) {
            $previous = Revision::where('page_id', $r->page_id)->where('number', '<', $r->number)->orderByDesc('number')->first();
            $before = $previous ? $this->snapshotFor($user, $previous->snapshot) : [];
            $after = $this->snapshotFor($user, $r->snapshot);

            return ['id' => $r->id, 'page_id' => $r->page_id, 'title' => $after['title'], 'number' => $r->number, 'action' => $r->action, 'reason' => $r->reason, 'at' => $r->created_at, 'diff' => $this->documents->diff($before['document'] ?? [], $after['document']), 'metadata' => collect(['title', 'parent_id', 'status', 'visibility', 'owner_label', 'sources'])->filter(fn ($key) => ($before[$key] ?? null) !== ($after[$key] ?? null))->mapWithKeys(fn ($key) => [$key => ['before' => $before[$key] ?? null, 'after' => $after[$key] ?? null]])];
        })->all();
    }
}

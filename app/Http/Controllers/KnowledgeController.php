<?php

namespace App\Http\Controllers;

use App\Jobs\IndexPage;
use App\Jobs\RunAudit;
use App\Models\Asset;
use App\Models\Audit;
use App\Models\CallRecording;
use App\Models\Chunk;
use App\Models\Page;
use App\Models\Publication;
use App\Models\Revision;
use App\Models\Setting;
use App\Models\User;
use App\Models\WidgetDefinition;
use App\Services\Access;
use App\Services\CallLibrary;
use App\Services\CallTranscription;
use App\Services\Documents;
use App\Services\Exports;
use App\Services\Knowledge;
use App\Services\OpenRouter;
use App\Services\Search;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class KnowledgeController extends Controller
{
    public function __construct(public Knowledge $kb, public Access $access) {}

    public function shell(Request $r)
    {
        return Inertia::render('Workspace', ['user' => $r->user()->only('id', 'name', 'email', 'role', 'can_view_calls', 'can_listen_calls')]);
    }

    public function tree(Request $r)
    {
        return $this->access->pages($r->user())->map(fn ($p) => $p->only(['id', 'parent_id', 'title', 'kind', 'status', 'visibility', 'revision', 'updated_at']))->values();
    }

    public function show(Request $r, Page $page)
    {
        $this->access->requireRead($r->user(), $page);

        return $this->kb->present($page);
    }

    public function exportPage(Request $r, Page $page, string $format, Exports $exports)
    {
        return $exports->download($r->user(), $page, $format);
    }

    public function save(Request $r, ?Page $page = null)
    {
        $data = $r->all();
        $requestId = $r->header('Idempotency-Key');
        $run = fn () => $this->kb->present($this->kb->save($r->user(), $data, $page));

        return $requestId ? $this->kb->operation($r->user(), $requestId, ['page' => $page?->id, ...$data], $run) : $run();
    }

    public function revisions(Request $r, Page $page)
    {
        $this->access->requireRead($r->user(), $page);

        return $page->revisions()->orderByDesc('number')->get()->map(fn ($v) => ['id' => $v->id, 'number' => $v->number, 'action' => $v->action, 'reason' => $v->reason, 'created_at' => $v->created_at, 'snapshot' => $this->kb->snapshotFor($r->user(), $v->snapshot)]);
    }

    public function restore(Request $r, Page $page, Revision $revision)
    {
        abort_unless($revision->page_id === $page->id, 404);

        return $this->kb->present($this->kb->save($r->user(), [...$revision->snapshot, 'expected_revision' => $r->integer('expected_revision'), 'reason' => 'Восстановление редакции '.$revision->number], $page));
    }

    public function publish(Request $r, Page $page)
    {
        $data = $r->validate(['expected_revision' => 'required|integer', 'indexed' => 'required|boolean', 'slug' => 'sometimes|nullable|string|max:180', 'children' => 'sometimes|array', 'children.*.id' => 'required|uuid', 'children.*.revision' => 'required|integer']);

        return DB::transaction(function () use ($r, $page, $data) {
            $pub = $this->kb->publish($r->user(), $page, $data['expected_revision'], $data['indexed'], $data['slug'] ?? null);
            foreach ($data['children'] ?? [] as $child) {
                $p = Page::findOrFail($child['id']);
                $walk = $p;
                $found = false;
                while ($walk->parent_id) {
                    if ($walk->parent_id === $page->id) {
                        $found = true;
                        break;
                    } $walk = Page::findOrFail($walk->parent_id);
                }
                abort_unless($found, 422, 'Страница не входит в выбранный раздел.');
                $this->kb->publish($r->user(), $p, $child['revision'], $data['indexed']);
            }

            return ['publication' => $pub, 'page' => $this->kb->present($page->fresh())];
        });
    }

    public function unpublish(Request $r, Page $page)
    {
        $r->validate(['expected_revision' => 'required|integer']);
        $this->kb->unpublish($r->user(), $page, $r->integer('expected_revision'));

        return $this->kb->present($page->fresh());
    }

    public function publicPage(Publication $publication)
    {
        $revision = Revision::findOrFail($publication->revision_id);
        $children = Publication::whereIn('page_id', Page::where('parent_id', $publication->page_id)->pluck('id'))->get()->map(function ($pub) {
            $snap = Revision::findOrFail($pub->revision_id)->snapshot;

            return ['id' => $pub->slug ?? $pub->id, 'title' => $snap['title']];
        });

        return response()->view('public', ['publication' => $publication, 'page' => $revision->snapshot, 'html' => app(Documents::class)->html($revision->snapshot['document'], $publication->id), 'children' => $children])->header('X-Robots-Tag', $publication->indexed ? 'index, follow' : 'noindex, nofollow')->header('Cache-Control', 'no-store, private');
    }

    public function search(Request $r, Search $search)
    {
        $r->validate(['q' => 'required|string|max:500']);

        return $search->run($r->user(), $r->string('q')->toString(), $r->boolean('semantic', true));
    }

    public function changes(Request $r)
    {
        $d = $r->validate(['since' => 'required|date', 'until' => 'nullable|date|after_or_equal:since']);

        return $this->kb->changes($r->user(), $d['since'], $d['until'] ?? null);
    }

    public function widgets()
    {
        return WidgetDefinition::orderBy('created_at')->get();
    }

    public function createWidget(Request $r)
    {
        abort_unless($r->user()->canEdit(), 403);
        $data = $r->validate(['key' => 'required|regex:/^[a-z][a-z0-9_]{2,50}$/|unique:widget_definitions,key', 'name' => 'required|string|max:120', 'description' => 'nullable|string|max:500', 'view' => 'required|in:fields,checklist,calculator', 'fields' => 'required|array|min:1|max:20', 'fields.*.key' => 'required|regex:/^[a-z][a-z0-9_]{0,40}$/|distinct', 'fields.*.label' => 'required|string|max:120', 'fields.*.type' => 'required|in:text,textarea,number,boolean', 'fields.*.required' => 'sometimes|boolean']);

        return WidgetDefinition::create($data);
    }

    public function upload(Request $r, Page $page)
    {
        $this->access->requireEdit($r->user(), $page);
        $r->validate(['file' => 'required|file|max:204800|mimes:jpg,jpeg,png,webp,gif,mp3,wav,mp4,webm,pdf']);
        $file = $r->file('file');
        $path = $file->store('assets', config('filesystems.knowledge_disk', 'local'));

        return Asset::create(['page_id' => $page->id, 'user_id' => $r->user()->id, 'name' => $file->getClientOriginalName(), 'mime' => $file->getMimeType(), 'disk' => config('filesystems.knowledge_disk', 'local'), 'path' => $path, 'size' => $file->getSize()])->only(['id', 'name', 'mime', 'size']);
    }

    public function media(Request $r, Asset $asset, Documents $docs)
    {
        if ($r->query('publication')) {
            $pub = Publication::findOrFail($r->query('publication'));
            abort_unless($pub->page_id === $asset->page_id, 404);
            $doc = Revision::findOrFail($pub->revision_id)->snapshot['document'];
            abort_unless(in_array($asset->id, $docs->assetIds($doc)), 404);
        } else {
            abort_unless($r->user(), 401);
            $call = CallRecording::where('asset_id', $asset->id)->first();
            if ($call) {
                app(CallLibrary::class)->requireListen($r->user(), $call);
            } elseif ($asset->page_id) {
                $this->access->requireRead($r->user(), Page::findOrFail($asset->page_id));
            } else {
                abort(404);
            }
        }
        if ($asset->disk !== 'local') {
            return redirect(Storage::disk($asset->disk)->temporaryUrl($asset->path, now()->addSeconds(60)));
        }

        if (! in_array($asset->mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'audio/mpeg', 'audio/wav', 'audio/flac', 'audio/ogg', 'audio/mp4', 'audio/webm', 'audio/aac', 'video/mp4', 'video/webm', 'application/pdf'])) {
            return response()->download(Storage::disk('local')->path($asset->path), $asset->name, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
        }

        return response()->file(Storage::disk('local')->path($asset->path), ['Content-Type' => $asset->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function settings(Request $r, OpenRouter $ai, CallTranscription $transcriber)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $c = $ai->config();

        return ['transcription' => $transcriber->publicConfig($ai), 'openrouter' => ['configured' => ! empty($c['key']), 'chat_model' => $c['chat_model'], 'embedding_model' => $c['embedding_model']], 'index' => ['total' => Chunk::count(), 'indexed' => Chunk::where('model', $c['embedding_model'])->whereNotNull('embedding')->count()], 'tokens' => $r->user()->tokens()->get(['id', 'name', 'abilities', 'created_at', 'last_used_at']), 'users' => User::all(['id', 'name', 'email', 'role', 'can_view_calls', 'can_listen_calls'])];
    }

    public function configure(Request $r, OpenRouter $ai, CallTranscription $transcriber)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $d = $r->validate(['key' => 'nullable|string|max:1000', 'chat_model' => 'required|string|max:150', 'embedding_model' => 'required|string|max:150']);
        $c = $ai->config();
        Setting::updateOrCreate(['key' => 'openrouter'], ['value' => ['key' => ($d['key'] ?? null) ?: ($c['key'] ?? ''), 'chat_model' => $d['chat_model'], 'embedding_model' => $d['embedding_model']]]);

        $transcriber->resumeWaiting($ai);

        return ['saved' => true];
    }

    public function index(Request $r)
    {
        abort_unless($r->user()->role === 'admin', 403);
        foreach (Page::whereNull('archived_at')->get() as $p) {
            IndexPage::dispatch($p->id, $p->revision);
        }

        return ['queued' => true];
    }

    public function tokens(Request $r)
    {
        return $r->user()->tokens()->get(['id', 'name', 'abilities', 'created_at', 'last_used_at']);
    }

    public function token(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:100', 'write' => 'required|boolean']);
        abort_if($d['write'] && ! $r->user()->canEdit(), 403);

        return ['token' => $r->user()->createToken($d['name'], $d['write'] ? ['kb:read', 'kb:write'] : ['kb:read'], now()->addDays(90))->plainTextToken];
    }

    public function revoke(Request $r, int $token)
    {
        $r->user()->tokens()->whereKey($token)->delete();

        return ['revoked' => true];
    }

    public function user(Request $r)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $d = $r->validate(['name' => 'required|string|max:120', 'email' => 'required|email|unique:users,email', 'password' => 'required|string|min:12|max:200', 'role' => 'required|in:admin,editor,reader', 'can_view_calls' => 'sometimes|boolean', 'can_listen_calls' => 'sometimes|boolean']);
        abort_if(($d['can_listen_calls'] ?? false) && ! ($d['can_view_calls'] ?? false), 422, 'Для прослушивания разрешите просмотр звонков.');

        return User::create($d);
    }

    public function role(Request $r, User $user)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $d = $r->validate(['role' => 'required|in:admin,editor,reader']);
        abort_if($user->id === $r->user()->id && $d['role'] !== 'admin', 422, 'Нельзя снять свою роль администратора.');
        $user->update($d);
        $user->tokens()->delete();

        return $user;
    }

    public function callPermissions(Request $r, User $user): User
    {
        abort_unless($r->user()->role === 'admin', 403);
        $data = $r->validate(['can_view_calls' => 'required|boolean', 'can_listen_calls' => 'required|boolean']);
        abort_if($data['can_listen_calls'] && ! $data['can_view_calls'], 422, 'Для прослушивания разрешите просмотр звонков.');
        $user->update($data);

        return $user;
    }

    public function audit(Request $r, OpenRouter $ai)
    {
        abort_unless($r->user()->canEdit(), 403);
        abort_unless($ai->ready(), 422, 'Сначала подключите OpenRouter в настройках.');
        $d = $r->validate(['topic' => 'required|string|max:500']);
        $audit = Audit::create(['user_id' => $r->user()->id, ...$d]);
        RunAudit::dispatch($audit->id);

        return $audit;
    }

    public function auditShow(Request $r, Audit $audit)
    {
        $this->auditAccess($r, $audit);

        return $audit;
    }

    private function auditAccess(Request $r, Audit $audit): void
    {
        abort_unless($audit->user_id === $r->user()->id, 404);
        foreach ($audit->report['coverage']['pages'] ?? [] as $id) {
            $this->access->requireRead($r->user(), Page::findOrFail($id));
        }
    }

    public function auditApply(Request $r, Audit $audit)
    {
        $this->auditAccess($r, $audit);
        $d = $r->validate(['conflict' => 'required|integer|min:0', 'option' => 'required|integer|min:0']);

        return DB::transaction(function () use ($r, $audit, $d) {
            $audit = Audit::whereKey($audit->id)->lockForUpdate()->firstOrFail();
            $report = $audit->report;
            abort_if(in_array($d['conflict'], $report['applied'] ?? []), 409, 'Этот конфликт уже обработан.');
            $option = $report['conflicts'][$d['conflict']]['options'][$d['option']] ?? null;
            abort_unless($option, 422);
            foreach (collect($option['patches'])->groupBy('page_id')->sortKeys() as $id => $patches) {
                $page = Page::findOrFail($id);
                $doc = $page->document;
                foreach ($patches as $patch) {
                    foreach ($doc['content'] as &$node) {
                        if (($node['attrs']['id'] ?? '') === $patch['block_id']) {
                            $node['content'] = $patch['after'] === '' ? [] : [['type' => 'text', 'text' => $patch['after']]];
                        }
                    }
                }
                unset($node);
                $this->kb->save($r->user(), [...$page->toArray(), 'document' => $doc, 'expected_revision' => $patches->first()['expected_revision'], 'reason' => 'МЕТА-ИИ: '.$option['label']], $page);
            }
            $report['applied'][] = $d['conflict'];
            $audit->update(['report' => $report]);

            return $audit;
        });
    }
}

<?php

namespace App\Mcp\Tools;

use App\Models\Page;
use App\Models\WidgetDefinition;
use App\Services\Knowledge;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class KbRead extends Tool
{
    protected string $name = 'kb_read';

    protected string $description = 'Read rules, navigation tree, widget schemas, a page or its immutable revisions. Always read rules before editing.';

    public function schema(JsonSchema $s): array
    {
        return ['mode' => $s->string()->enum(['rules', 'tree', 'page', 'history', 'widgets'])->required(), 'page_id' => $s->string()];
    }

    public function handle(Request $r, Knowledge $kb): Response
    {
        abort_unless($r->user()?->tokenCan('kb:read'), 403);
        $mode = $r->get('mode');
        if ($mode === 'rules') {
            return Response::text(file_get_contents(base_path('docs/MCP-CONTRACT.md')));
        }
        if ($mode === 'tree') {
            return Response::json(['pages' => $kb->access->pages($r->user())->map(fn ($p) => $p->only(['id', 'parent_id', 'title', 'kind', 'revision', 'status']))]);
        }
        if ($mode === 'widgets') {
            return Response::json(['widgets' => WidgetDefinition::all()]);
        }
        $page = Page::findOrFail($r->get('page_id'));
        $kb->access->requireRead($r->user(), $page);

        return Response::json($mode === 'history' ? ['revisions' => $page->revisions()->orderByDesc('number')->get()->map(fn ($revision) => [...$revision->toArray(), 'snapshot' => $kb->snapshotFor($r->user(), $revision->snapshot)])] : $kb->present($page));
    }
}

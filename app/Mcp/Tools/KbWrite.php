<?php

namespace App\Mcp\Tools;

use App\Models\Page;
use App\Services\Knowledge;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class KbWrite extends Tool
{
    protected string $name = 'kb_write';

    protected string $description = 'Create/update/archive a page, or publish/unpublish its snapshot. Payload is JSON string. For update include full page fields, document, expected_revision and reason. Requires kb:write. Idempotent request_id must be unique per intended mutation. Never publish without explicit user instruction.';

    public function schema(JsonSchema $s): array
    {
        return ['action' => $s->string()->enum(['create', 'update', 'publish', 'unpublish'])->required(), 'page_id' => $s->string(), 'request_id' => $s->string()->required(), 'payload_json' => $s->string()->required()];
    }

    public function handle(Request $r, Knowledge $kb): Response
    {
        abort_unless($r->user()?->tokenCan('kb:write') && $r->user()->canEdit(), 403);
        $r->validate(['action' => 'required|in:create,update,publish,unpublish', 'request_id' => 'required|string|min:8|max:100', 'payload_json' => 'required|json']);
        $payload = json_decode($r->get('payload_json'), true);
        abort_unless(is_array($payload), 422);
        $result = $kb->operation($r->user(), $r->get('request_id'), $r->all(), function () use ($r, $kb, $payload) {
            $action = $r->get('action');
            $page = $action === 'create' ? null : Page::findOrFail($r->get('page_id'));
            if ($action === 'publish') {
                $kb->publish($r->user(), $page, (int) ($payload['expected_revision'] ?? 0), (bool) ($payload['indexed'] ?? false));

                return $kb->present($page->fresh());
            }
            if ($action === 'unpublish') {
                $kb->unpublish($r->user(), $page, (int) ($payload['expected_revision'] ?? 0));

                return $kb->present($page->fresh());
            }

            return $kb->present($kb->save($r->user(), $payload, $page));
        });

        return Response::json($result);
    }
}

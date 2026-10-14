<?php

namespace App\Mcp\Tools;

use App\Services\Knowledge;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class KbChanges extends Tool
{
    protected string $name = 'kb_changes';

    protected string $description = 'Get an evidence-backed change journal since a date, including before/after block text and metadata changes. Access filtered.';

    public function schema(JsonSchema $s): array
    {
        return ['since' => $s->string()->required(), 'until' => $s->string()];
    }

    public function handle(Request $r, Knowledge $kb): Response
    {
        abort_unless($r->user()?->tokenCan('kb:read'), 403);
        $d = $r->validate(['since' => 'required|date', 'until' => 'nullable|date|after_or_equal:since']);

        return Response::json(['changes' => $kb->changes($r->user(), $d['since'], $d['until'] ?? null)]);
    }
}

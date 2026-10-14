<?php

namespace App\Mcp\Tools;

use App\Services\Search;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class KbSearch extends Tool
{
    protected string $name = 'kb_search';

    protected string $description = 'Search accessible active knowledge. Returns lexical or hybrid mode, source page ids and block ids.';

    public function schema(JsonSchema $s): array
    {
        return ['query' => $s->string()->required()];
    }

    public function handle(Request $r, Search $search): Response
    {
        abort_unless($r->user()?->tokenCan('kb:read'), 403);
        $r->validate(['query' => 'required|string|max:500']);

        return Response::json($search->run($r->user(), $r->get('query')));
    }
}

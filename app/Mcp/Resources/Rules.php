<?php

namespace App\Mcp\Resources;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class Rules extends Resource
{
    protected string $uri = 'kb://rules';

    protected string $mimeType = 'text/markdown';

    public function handle(Request $r): Response
    {
        abort_unless($r->user()?->tokenCan('kb:read'), 403);

        return Response::text(file_get_contents(base_path('docs/MCP-CONTRACT.md')));
    }
}

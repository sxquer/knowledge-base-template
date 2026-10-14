<?php

use App\Mcp\KnowledgeServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', KnowledgeServer::class)->middleware(['auth:sanctum', 'throttle:120,1']);

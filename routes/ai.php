<?php

use App\Mcp\Servers\StagMcpServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/stag', StagMcpServer::class)
    ->middleware('auth:sanctum')
    ->name('mcp.stag');

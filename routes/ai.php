<?php

use App\Mcp\Servers\StagMcpServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/stag', StagMcpServer::class)
    ->name('mcp.stag');

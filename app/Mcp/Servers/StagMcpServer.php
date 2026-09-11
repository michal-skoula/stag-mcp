<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\ListNotificationsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('IS-STAG Server')]
#[Instructions('Provides tools and resources for interacting with IS-STAG.')]
class StagMcpServer extends Server
{
    protected array $tools = [
        ListNotificationsTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}

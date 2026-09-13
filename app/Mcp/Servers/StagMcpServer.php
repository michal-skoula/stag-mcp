<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetBudovyTool;
use App\Mcp\Tools\GetHarmonogramTool;
use App\Mcp\Tools\GetKalendarTool;
use App\Mcp\Tools\GetMistnostiTool;
use App\Mcp\Tools\GetPredmetInfoTool;
use App\Mcp\Tools\GetZnamkyTool;
use App\Mcp\Tools\ListNotificationsTool;
use App\Mcp\Tools\MarkNotificationsReadTool;
use App\Mcp\Tools\SearchPredmetyTool;
use App\Mcp\Tools\StudyAdvisorTool;
use App\Mcp\Tools\UserInfoTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('IS-STAG Server')]
#[Instructions('Provides tools and resources for interacting with IS-STAG.')]
class StagMcpServer extends Server
{
    protected array $tools = [
        ListNotificationsTool::class,
        MarkNotificationsReadTool::class,
        GetBudovyTool::class,
        GetMistnostiTool::class,
        SearchPredmetyTool::class,
        GetPredmetInfoTool::class,
        GetHarmonogramTool::class,
        GetKalendarTool::class,
        GetZnamkyTool::class,
        UserInfoTool::class,
        StudyAdvisorTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}

<?php

namespace App\Mcp\Servers;

use App\Mcp\Methods\InstrumentedCallTool;
use App\Mcp\Tools\GetBudovyTool;
use App\Mcp\Tools\GetHarmonogramTool;
use App\Mcp\Tools\GetKalendarTool;
use App\Mcp\Tools\GetPredmetInfoTool;
use App\Mcp\Tools\GetUcitelInfoTool;
use App\Mcp\Tools\GetZnamkyTool;
use App\Mcp\Tools\ListNotificationsTool;
use App\Mcp\Tools\ListRoomsTool;
use App\Mcp\Tools\MarkNotificationsReadTool;
use App\Mcp\Tools\ReportIssueTool;
use App\Mcp\Tools\SearchPredmetyTool;
use App\Mcp\Tools\SearchUciteleTool;
use App\Mcp\Tools\StudyAdvisorTool;
use App\Mcp\Tools\WhoAmITool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\Methods\CompletionComplete;
use Laravel\Mcp\Server\Methods\GetPrompt;
use Laravel\Mcp\Server\Methods\ListPrompts;
use Laravel\Mcp\Server\Methods\ListResources;
use Laravel\Mcp\Server\Methods\ListResourceTemplates;
use Laravel\Mcp\Server\Methods\ListTools;
use Laravel\Mcp\Server\Methods\Ping;
use Laravel\Mcp\Server\Methods\ReadResource;

#[Name('IS-STAG Server')]
#[Instructions('Provides tools and resources for interacting with IS-STAG.')]
class StagMcpServer extends Server
{
    protected array $tools = [
        WhoAmITool::class,
        ListNotificationsTool::class,
        MarkNotificationsReadTool::class,
        GetBudovyTool::class,
        ListRoomsTool::class,
        SearchPredmetyTool::class,
        GetPredmetInfoTool::class,
        GetHarmonogramTool::class,
        GetKalendarTool::class,
        GetZnamkyTool::class,
        StudyAdvisorTool::class,
        SearchUciteleTool::class,
        GetUcitelInfoTool::class,
        ReportIssueTool::class,
    ];

    /**
     * `tools/call` is swapped for a version that tags the log context with the
     * tool name and fires McpToolCalled, so no tool has to instrument itself.
     *
     * @var array<string, class-string<Method>>
     */
    protected array $methods = [
        'tools/list' => ListTools::class,
        'tools/call' => InstrumentedCallTool::class,
        'resources/list' => ListResources::class,
        'resources/read' => ReadResource::class,
        'resources/templates/list' => ListResourceTemplates::class,
        'prompts/list' => ListPrompts::class,
        'prompts/get' => GetPrompt::class,
        'completion/complete' => CompletionComplete::class,
        'ping' => Ping::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}

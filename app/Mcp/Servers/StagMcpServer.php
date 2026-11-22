<?php

namespace App\Mcp\Servers;

use App\Mcp\Methods\InstrumentedCallTool;
use App\Mcp\Tools\GetAcademicCalendarTool;
use App\Mcp\Tools\GetGradesTool;
use App\Mcp\Tools\GetProfileTool;
use App\Mcp\Tools\GetProgramInfoTool;
use App\Mcp\Tools\GetStudijniPlanTool;
use App\Mcp\Tools\GetStudijniPokrokTool;
use App\Mcp\Tools\GetStudyAdvisorTool;
use App\Mcp\Tools\GetSubjectTool;
use App\Mcp\Tools\GetTeacherTool;
use App\Mcp\Tools\GetTimetableTool;
use App\Mcp\Tools\ListBuildingsTool;
use App\Mcp\Tools\ListCertifikatyTool;
use App\Mcp\Tools\ListExamDatesTool;
use App\Mcp\Tools\ListNotificationsTool;
use App\Mcp\Tools\ListRoomsTool;
use App\Mcp\Tools\MarkNotificationsReadTool;
use App\Mcp\Tools\ReportIssueTool;
use App\Mcp\Tools\SearchProgramyTool;
use App\Mcp\Tools\SearchSubjectsTool;
use App\Mcp\Tools\SearchTeachersTool;
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
    /**
     * The default page is 15 tools. Clients that do not follow the cursor would
     * never see the rest, so one page has to hold the whole list.
     */
    public int $defaultPaginationLength = 50;

    protected array $tools = [
        GetProfileTool::class,
        ListNotificationsTool::class,
        MarkNotificationsReadTool::class,
        ListBuildingsTool::class,
        ListRoomsTool::class,
        SearchSubjectsTool::class,
        GetSubjectTool::class,
        GetAcademicCalendarTool::class,
        GetTimetableTool::class,
        GetGradesTool::class,
        GetStudyAdvisorTool::class,
        SearchTeachersTool::class,
        GetTeacherTool::class,
        GetStudijniPlanTool::class,
        GetStudijniPokrokTool::class,
        ListCertifikatyTool::class,
        ListExamDatesTool::class,
        SearchProgramyTool::class,
        GetProgramInfoTool::class,
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

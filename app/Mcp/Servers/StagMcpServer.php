<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetAcademicCalendarTool;
use App\Mcp\Tools\GetGradesTool;
use App\Mcp\Tools\GetProfileTool;
use App\Mcp\Tools\GetStudyAdvisorTool;
use App\Mcp\Tools\GetSubjectTool;
use App\Mcp\Tools\GetTeacherTool;
use App\Mcp\Tools\GetTimetableTool;
use App\Mcp\Tools\ListBuildingsTool;
use App\Mcp\Tools\ListNotificationsTool;
use App\Mcp\Tools\ListRoomsTool;
use App\Mcp\Tools\MarkNotificationsReadTool;
use App\Mcp\Tools\SearchSubjectsTool;
use App\Mcp\Tools\SearchTeachersTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('IS-STAG Server')]
#[Instructions('Provides tools and resources for interacting with IS-STAG.')]
class StagMcpServer extends Server
{
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
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}

<?php

namespace Tests\Fixtures;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * Carries StagLoginProbeTool, which the real server must not advertise.
 */
#[Name('STAG Login Test Server')]
class StagLoginTestServer extends Server
{
    protected array $tools = [
        StagLoginProbeTestTool::class,
    ];
}

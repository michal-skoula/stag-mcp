<?php

namespace Tests\Fixtures;

use App\Contracts\StagClient;
use App\Mcp\Concerns\RequiresStagLogin;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

/**
 * The smallest tool built on RequiresStagLogin, so the trait's own behaviour can
 * be covered once instead of once per tool that uses it.
 */
#[Name('stag-login-probe')]
#[Title('STAG Login Probe')]
#[Description('Test fixture. Calls STAG and reports that the handler ran.')]
class StagLoginProbeTestTool extends Tool
{
    use RequiresStagLogin;

    protected function handleForStagUser(Request $request, StagClient $stag): ResponseFactory|Response
    {
        $stag->get('probe');

        return Response::structured(['reached' => true]);
    }
}

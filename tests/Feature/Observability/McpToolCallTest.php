<?php

use App\Events\McpToolCalled;
use App\Events\StagRequestCompleted;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

function callTool(User $user, string $name, array $arguments = []): TestResponse
{
    return test()->withToken($user->createToken('mcp')->plainTextToken)->postJson(route('mcp.stag'), [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $name, 'arguments' => $arguments],
    ]);
}

it('fires an event with the tool name and the caller id, not the arguments', function () {
    Event::fake([McpToolCalled::class]);
    Http::fake(['stag-ws.zcu.cz/*' => Http::response(['items' => []])]);
    $user = User::factory()->create();

    callTool($user, 'list-budovy', ['city' => 'Plzeň'])->assertOk();

    Event::assertDispatched(McpToolCalled::class, fn (McpToolCalled $event) => $event->tool === 'list-budovy'
        && $event->userId === $user->id
        && $event->failed === false
        && $event->durationMs >= 0
        && ! str_contains(json_encode($event), 'Plzeň'));
});

it('marks a call that returned a tool error as failed', function () {
    Event::fake([McpToolCalled::class]);
    Http::fake(['stag-ws.zcu.cz/*' => Http::response('boom', 500)]);

    callTool(User::factory()->create(), 'list-budovy')->assertOk();

    Event::assertDispatched(McpToolCalled::class, fn (McpToolCalled $event) => $event->failed === true);
});

it('tags the STAG requests of a call with the tool name and clears it afterwards', function () {
    Event::fake([StagRequestCompleted::class]);
    Http::fake(['stag-ws.zcu.cz/*' => Http::response(['items' => []])]);

    callTool(User::factory()->create(), 'list-budovy')->assertOk();

    Event::assertDispatched(StagRequestCompleted::class, fn (StagRequestCompleted $event) => $event->tool === 'list-budovy');
    expect(Context::has('mcp_tool'))->toBeFalse();
});

it('logs the tool call with the user id and no arguments', function () {
    Http::fake(['stag-ws.zcu.cz/*' => Http::response(['items' => []])]);
    Log::spy();
    $user = User::factory()->create();

    callTool($user, 'list-budovy', ['city' => 'Plzeň'])->assertOk();

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'mcp.tool_call'
        && $context['tool'] === 'list-budovy'
        && $context['user_id'] === $user->id
        && ! str_contains(json_encode($context), 'Plzeň'))->once();
});

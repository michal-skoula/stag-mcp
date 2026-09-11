<?php

use App\Mcp\Servers\StagMcpServer;
use App\Mcp\Tools\MarkNotificationsReadTool;
use App\Models\User;
use Illuminate\Support\Facades\Http;

const STAG_PRECTENO = 'stag-ws.zcu.cz/ws/services/rest2/oznameni/precteno*';

// The gate itself is covered in TraitRequiresStagLoginTest. This is the one case that
// proves this tool sits behind it, which that file cannot show: it passes either way.
// Tool::handle() is not abstract, so a tool defining its own would skip the gate silently.
it('refuses a caller with no STAG ticket', function () {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(MarkNotificationsReadTool::class, ['ids' => [1]])
        ->assertHasErrors();

    Http::assertNothingSent();
});

it('marks a single notification as read', function () {
    Http::fake([STAG_PRECTENO => Http::response([])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(MarkNotificationsReadTool::class, ['ids' => [3764836]])
        ->assertOk();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), 'oznameni/precteno?notiIdno=3764836'));
});

it('sends several ids as repeated query keys', function () {
    Http::fake([STAG_PRECTENO => Http::response([])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(MarkNotificationsReadTool::class, ['ids' => [1, 2, 3]])
        ->assertOk();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), 'oznameni/precteno?notiIdno=1&notiIdno=2&notiIdno=3'));
});

it('sends a PUT', function () {
    Http::fake([STAG_PRECTENO => Http::response([])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(MarkNotificationsReadTool::class, ['ids' => [1]])
        ->assertOk();

    Http::assertSent(fn ($request) => $request->method() === 'PUT');
});

it('drops duplicate ids', function () {
    Http::fake([STAG_PRECTENO => Http::response([])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(MarkNotificationsReadTool::class, ['ids' => [1, 2, 1]])
        ->assertOk()
        ->assertSee('"count":2');

    Http::assertSent(fn ($request) => str_ends_with($request->url(), 'oznameni/precteno?notiIdno=1&notiIdno=2'));
});

it('echoes back the ids it marked', function () {
    Http::fake([STAG_PRECTENO => Http::response([])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(MarkNotificationsReadTool::class, ['ids' => [1, 2]])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"count":2')
        ->assertSee('"ids":[1,2]');
});

it('succeeds when STAG answers with an empty body', function () {
    Http::fake([STAG_PRECTENO => Http::response('', 204)]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(MarkNotificationsReadTool::class, ['ids' => [1]])
        ->assertOk()
        ->assertHasNoErrors();
});

it('rejects a call with no ids', function () {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(MarkNotificationsReadTool::class, ['ids' => []])
        ->assertHasErrors();

    Http::assertNothingSent();
});

it('rejects an id that is not an integer', function () {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(MarkNotificationsReadTool::class, ['ids' => ['not-a-number']])
        ->assertHasErrors();

    Http::assertNothingSent();
});

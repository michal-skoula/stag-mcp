<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\StagLoginProbeTestTool;
use Tests\Fixtures\StagLoginTestServer;

const STAG_PROBE = 'stag-ws.zcu.cz/ws/services/rest2/probe*';

it('refuses a call with no authenticated user', function () {
    Http::fake();

    StagLoginTestServer::tool(StagLoginProbeTestTool::class)
        ->assertHasErrors()
        ->assertSee('must send a bearer token');

    Http::assertNothingSent();
});

it('tells the user to authorize when no ticket is stored', function () {
    Http::fake();

    StagLoginTestServer::actingAs(User::factory()->create())
        ->tool(StagLoginProbeTestTool::class)
        ->assertHasErrors()
        ->assertSee('has not authorized IS-STAG');

    Http::assertNothingSent();
});

it('refuses a lapsed ticket without troubling STAG', function () {
    Http::fake();

    StagLoginTestServer::actingAs(User::factory()->withExpiredStagToken()->create())
        ->tool(StagLoginProbeTestTool::class)
        ->assertHasErrors()
        ->assertSee('expired or been revoked');

    Http::assertNothingSent();
});

it('hands the handler a client bound to the caller', function () {
    Http::fake([STAG_PROBE => Http::response([])]);

    StagLoginTestServer::actingAs(User::factory()->withStagToken('a-ticket')->create())
        ->tool(StagLoginProbeTestTool::class)
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"reached":true');

    Http::assertSent(fn ($request) => $request->hasHeader('Cookie', 'WSCOOKIE=a-ticket'));
});

it('tells the user to re-authorize when STAG rejects the ticket', function () {
    Http::fake([STAG_PROBE => Http::response('Unauthorized', 401)]);

    StagLoginTestServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(StagLoginProbeTestTool::class)
        ->assertHasErrors()
        ->assertSee('expired or been revoked');
});

it('does not leak the STAG error body into the response', function () {
    Http::fake([STAG_PROBE => Http::response('K volání této služby je potřeba se přihlásit', 401)]);

    StagLoginTestServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(StagLoginProbeTestTool::class)
        ->assertHasErrors()
        ->assertDontSee('potřeba se přihlásit');
});

it('reports an unexpected status without detail', function () {
    Http::fake([STAG_PROBE => Http::response('boom', 500)]);

    StagLoginTestServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(StagLoginProbeTestTool::class)
        ->assertHasErrors()
        ->assertSee('unexpected HTTP 500');
});

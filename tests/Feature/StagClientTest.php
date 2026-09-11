<?php

use App\Clients\StagClient;
use App\Exceptions\StagException;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

const ANY_STAG = 'stag-ws.zcu.cz/*';

it('attaches the ticket as a cookie when the user has one', function () {
    Http::fake([ANY_STAG => Http::response([])]);

    (new StagClient(User::factory()->withStagToken('a-ticket')->create()))
        ->get('ciselniky/getCiselnik');

    Http::assertSent(fn ($request) => $request->hasHeader('Cookie', 'WSCOOKIE=a-ticket'));
});

it('calls anonymous services without a cookie at all', function () {
    Http::fake([ANY_STAG => Http::response([])]);

    (new StagClient(User::factory()->create()))
        ->get('ciselniky/getCiselnik');

    Http::assertSent(fn ($request) => ! $request->hasHeader('Cookie'));
});

it('tells an unauthorized caller to authorize when STAG refuses', function () {
    Http::fake([ANY_STAG => Http::response('Unauthorized', 401)]);

    expect(fn () => (new StagClient(User::factory()->create()))->get('oznameni/list'))
        ->toThrow(StagException::class, 'has not authorized IS-STAG');
});

it('tells a ticket holder to re-authorize when STAG refuses', function () {
    Http::fake([ANY_STAG => Http::response('Unauthorized', 401)]);

    expect(fn () => (new StagClient(User::factory()->withStagToken()->create()))->get('oznameni/list'))
        ->toThrow(StagException::class, 'expired or been revoked');
});

it('reports a refused role separately from a refused ticket', function () {
    Http::fake([ANY_STAG => Http::response('Forbidden', 403)]);

    expect(fn () => (new StagClient(User::factory()->withStagToken()->create()))->get('oznameni/list'))
        ->toThrow(StagException::class, 'refused the requested role');
});

it('reports an unreachable server', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(fn () => (new StagClient(User::factory()->withStagToken()->create()))->get('oznameni/list'))
        ->toThrow(StagException::class, 'Could not reach');
});

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

it('expands array query values into repeated keys', function () {
    Http::fake([ANY_STAG => Http::response([])]);

    (new StagClient(User::factory()->withStagToken()->create()))
        ->put('oznameni/precteno', ['notiIdno' => [1, 2, 3]]);

    // Asserting on the URL, not $request['notiIdno']. Laravel runs the recorded
    // query through parse_str, which keeps only the last value and would pass
    // even if the expansion wrote notiIdno[0]=1.
    Http::assertSent(fn ($request) => str_ends_with($request->url(), 'oznameni/precteno?notiIdno=1&notiIdno=2&notiIdno=3'));
});

it('returns an empty array when STAG sends no body', function () {
    Http::fake([ANY_STAG => Http::response('', 204)]);

    $result = (new StagClient(User::factory()->withStagToken()->create()))
        ->put('oznameni/precteno', ['notiIdno' => [1]]);

    expect($result)->toBe([]);
});

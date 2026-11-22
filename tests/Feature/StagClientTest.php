<?php

use App\Clients\StagHttpClient;
use App\Events\StagRequestCompleted;
use App\Exceptions\StagException;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const ANY_STAG = 'stag-ws.zcu.cz/*';

it('attaches the ticket as a cookie when the user has one', function () {
    Http::fake([ANY_STAG => Http::response([])]);

    (new StagHttpClient(User::factory()->withStagToken('a-ticket')->create()))
        ->get('ciselniky/getCiselnik');

    Http::assertSent(fn ($request) => $request->hasHeader('Cookie', 'WSCOOKIE=a-ticket'));
});

it('calls anonymous services without a cookie at all', function () {
    Http::fake([ANY_STAG => Http::response([])]);

    (new StagHttpClient(User::factory()->create()))
        ->get('ciselniky/getCiselnik');

    Http::assertSent(fn ($request) => ! $request->hasHeader('Cookie'));
});

it('tells an unauthorized caller to authorize when STAG refuses', function () {
    Http::fake([ANY_STAG => Http::response('Unauthorized', 401)]);

    expect(fn () => (new StagHttpClient(User::factory()->create()))->get('oznameni/list'))
        ->toThrow(StagException::class, 'has not authorized IS-STAG');
});

it('tells a ticket holder to re-authorize when STAG refuses', function () {
    Http::fake([ANY_STAG => Http::response('Unauthorized', 401)]);

    expect(fn () => (new StagHttpClient(User::factory()->withStagToken()->create()))->get('oznameni/list'))
        ->toThrow(StagException::class, 'expired or been revoked');
});

it('reports a refused role separately from a refused ticket', function () {
    Http::fake([ANY_STAG => Http::response('Forbidden', 403)]);

    expect(fn () => (new StagHttpClient(User::factory()->withStagToken()->create()))->get('oznameni/list'))
        ->toThrow(StagException::class, 'refused the requested role');
});

it('reports an unreachable server', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(fn () => (new StagHttpClient(User::factory()->withStagToken()->create()))->get('oznameni/list'))
        ->toThrow(StagException::class, 'Could not reach');
});

it('expands array query values into repeated keys', function () {
    Http::fake([ANY_STAG => Http::response([])]);

    (new StagHttpClient(User::factory()->withStagToken()->create()))
        ->put('oznameni/precteno', ['notiIdno' => [1, 2, 3]]);

    // Asserting on the URL, not $request['notiIdno']. Laravel runs the recorded
    // query through parse_str, which keeps only the last value and would pass
    // even if the expansion wrote notiIdno[0]=1.
    Http::assertSent(fn ($request) => str_ends_with($request->url(), 'oznameni/precteno?notiIdno=1&notiIdno=2&notiIdno=3'));
});

it('returns an empty array when STAG sends no body', function () {
    Http::fake([ANY_STAG => Http::response('', 204)]);

    $result = (new StagHttpClient(User::factory()->withStagToken()->create()))
        ->put('oznameni/precteno', ['notiIdno' => [1]]);

    expect($result)->toBe([]);
});

it('fires an event with the endpoint, status and duration, and nothing from the query', function () {
    Event::fake([StagRequestCompleted::class]);
    Http::fake([ANY_STAG => Http::response(['ok' => true])]);
    $user = User::factory()->withStagToken('a-ticket')->create();

    (new StagHttpClient($user))->get('znamky/getZnamkyByStudent', ['osCislo' => 'A25B0093P']);

    Event::assertDispatched(StagRequestCompleted::class, fn (StagRequestCompleted $event) => $event->method === 'GET'
        && $event->endpoint === 'znamky/getZnamkyByStudent'
        && $event->status === 200
        && $event->successful
        && $event->userId === $user->id
        && $event->durationMs >= 0
        && ! str_contains(json_encode($event), 'A25B0093P')
        && ! str_contains(json_encode($event), 'a-ticket'));
});

it('tags the failed exception and the event with the status that STAG sent', function () {
    Event::fake([StagRequestCompleted::class]);
    Http::fake([ANY_STAG => Http::response('boom', 500)]);

    try {
        (new StagHttpClient(User::factory()->withStagToken()->create()))->get('vizitka/getVizitkaByUcitidno', ['ucitidno' => 1]);
    } catch (StagException $e) {
        expect($e->endpoint)->toBe('vizitka/getVizitkaByUcitidno')
            ->and($e->status)->toBe(500);
    }

    Event::assertDispatched(StagRequestCompleted::class, fn (StagRequestCompleted $event) => $event->status === 500 && ! $event->successful);
});

it('reports an unreachable server with no status', function () {
    Event::fake([StagRequestCompleted::class]);
    Http::fake(fn () => throw new ConnectionException('timed out'));

    try {
        (new StagHttpClient(User::factory()->withStagToken()->create()))->get('oznameni/list');
    } catch (StagException $e) {
        expect($e->endpoint)->toBe('oznameni/list')->and($e->status)->toBeNull();
    }

    Event::assertDispatched(StagRequestCompleted::class, fn (StagRequestCompleted $event) => $event->status === null && ! $event->successful);
});

it('logs each call without the ticket or the query string', function () {
    Http::fake([ANY_STAG => Http::response([])]);
    Log::spy();

    (new StagHttpClient(User::factory()->withStagToken('a-ticket')->create()))
        ->get('znamky/getZnamkyByStudent', ['osCislo' => 'A25B0093P']);

    Log::shouldHaveReceived('log')->withArgs(function (string $level, string $message, array $context) {
        return $level === 'info'
            && $message === 'stag.request'
            && $context['endpoint'] === 'znamky/getZnamkyByStudent'
            && $context['status'] === 200
            && ! str_contains(json_encode($context), 'A25B0093P')
            && ! str_contains(json_encode($context), 'a-ticket');
    })->once();
});

it('logs a failed call as a warning', function () {
    Http::fake([ANY_STAG => Http::response('boom', 500)]);
    Log::spy();

    try {
        (new StagHttpClient(User::factory()->withStagToken()->create()))->get('oznameni/list');
    } catch (StagException) {
    }

    Log::shouldHaveReceived('log')->withArgs(fn (string $level, string $message, array $context) => $level === 'warning'
        && $message === 'stag.request'
        && $context['status'] === 500)->once();
});

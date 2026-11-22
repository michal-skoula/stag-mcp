<?php

use App\Exceptions\StagDegradedException;
use App\Exceptions\StagException;
use App\Services\Observability\StagErrorReporter;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Exceptions;

it('reports the endpoint, status and handler without the original exception', function () {
    Exceptions::fake();
    $original = StagException::requestFailed(500, new RuntimeException('failed for https://stag.example/x?osCislo=A25B0093P'))
        ->at('ucitel/getGarant_ProgramOborByUcitIdno', 500);

    app(StagErrorReporter::class)->degraded($original, 'teacher guarantees');

    Exceptions::assertReported(fn (StagDegradedException $e) => $e->getPrevious() === null
        && ! str_contains($e->getMessage(), 'A25B0093P')
        && str_contains($e->getMessage(), 'ucitel/getGarant_ProgramOborByUcitIdno returned 500')
        && str_contains($e->getMessage(), 'teacher guarantees'));
});

it('names the tool that was running', function () {
    Exceptions::fake();
    Context::add('mcp_tool', 'who-am-i');

    app(StagErrorReporter::class)->degraded(StagException::unreachable()->at('help/x', null), 'role detail');

    Exceptions::assertReported(fn (StagDegradedException $e) => $e->tool === 'who-am-i'
        && str_contains($e->getMessage(), 'no response'));
});

it('logs a reported degradation at warning level', function () {
    $path = tempnam(sys_get_temp_dir(), 'stag-log');
    config(['logging.default' => 'single', 'logging.channels.single.path' => $path]);
    app('log')->forgetChannel('single');

    app(StagErrorReporter::class)->degraded(StagException::roleRejected()->at('vizitka/x', 403), 'business card');

    expect(file_get_contents($path))
        ->toContain('.WARNING:')
        ->toContain('vizitka/x returned 403');

    unlink($path);
});

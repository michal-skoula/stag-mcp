<?php

namespace App\Services\Observability;

use App\Exceptions\StagDegradedException;
use App\Exceptions\StagException;
use Illuminate\Support\Facades\Context;

/**
 * The one place a swallowed StagException is reported.
 *
 * Several tools degrade on purpose when a secondary STAG call fails: a
 * refused business card becomes empty offices, a refused guarantor lookup
 * becomes `null`. That is right for the user and invisible to us, so every
 * such catch block hands the exception here instead of dropping it.
 *
 * It goes through Laravel's `report()`, so whatever the exception handler is
 * wired to (the log, an error tracker added later) receives it with no change
 * at the call sites.
 */
class StagErrorReporter
{
    /**
     * @param  string  $handler  Short label for the code that swallowed it, e.g. "business card".
     */
    public function degraded(StagException $exception, string $handler): void
    {
        report(new StagDegradedException(
            $exception->endpoint,
            $exception->status,
            $handler,
            Context::get('mcp_tool'),
        ));
    }
}

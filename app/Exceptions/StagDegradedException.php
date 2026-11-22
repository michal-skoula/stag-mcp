<?php

namespace App\Exceptions;

use Exception;

/**
 * Stands in for a StagException that a tool chose to swallow, so it can be
 * reported without being thrown.
 *
 * It deliberately has no previous exception. The original wraps Laravel's
 * RequestException, whose message includes the request URL and with it the
 * query string (osCislo, ucitIdno). The fields here are what is safe to ship
 * to a log or an error tracker.
 */
class StagDegradedException extends Exception
{
    public function __construct(
        public readonly ?string $endpoint,
        public readonly ?int $stagStatus,
        public readonly string $handler,
        public readonly ?string $tool,
    ) {
        parent::__construct(sprintf(
            'STAG call degraded: %s returned %s, in %s%s',
            $endpoint ?? 'unknown endpoint',
            $stagStatus ?? 'no response',
            $handler,
            $tool === null ? '' : " (tool {$tool})",
        ));
    }
}

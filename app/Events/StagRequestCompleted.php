<?php

namespace App\Events;

/**
 * One finished call to the STAG web services, successful or not.
 *
 * Carries the endpoint path only, never the query string or body, because
 * those hold osCislo and other personal data. Nothing here is a ticket.
 */
final readonly class StagRequestCompleted
{
    public function __construct(
        public string $method,
        public string $endpoint,
        public ?int $status,
        public float $durationMs,
        public bool $successful,
        public ?int $userId,
        public ?string $tool,
    ) {}
}

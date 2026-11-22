<?php

namespace App\Events;

/**
 * One finished `tools/call` on the MCP server.
 *
 * Holds the tool name and the caller's user id. Arguments and results are
 * left out on purpose: they carry names, grades and student numbers.
 */
final readonly class McpToolCalled
{
    public function __construct(
        public string $tool,
        public ?int $userId,
        public float $durationMs,
        public bool $failed,
    ) {}
}

<?php

namespace App\Listeners;

use App\Events\McpToolCalled;
use App\Models\ToolCall;
use Throwable;

class RecordMcpToolCall
{
    /**
     * Telemetry must never break the tool call it is measuring, so a failed
     * write is reported and dropped.
     */
    public function handle(McpToolCalled $event): void
    {
        if (! config('telemetry.enabled')) {
            return;
        }

        try {
            ToolCall::create([
                'user_id' => $event->userId,
                'tool' => $event->tool,
                'failed' => $event->failed,
                'duration_ms' => $event->durationMs,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}

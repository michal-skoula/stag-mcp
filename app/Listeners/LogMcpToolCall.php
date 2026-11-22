<?php

namespace App\Listeners;

use App\Events\McpToolCalled;
use Illuminate\Support\Facades\Log;

class LogMcpToolCall
{
    public function handle(McpToolCalled $event): void
    {
        Log::info('mcp.tool_call', [
            'tool' => $event->tool,
            'user_id' => $event->userId,
            'duration_ms' => $event->durationMs,
            'failed' => $event->failed,
        ]);
    }
}

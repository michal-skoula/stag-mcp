<?php

namespace App\Listeners;

use App\Events\StagRequestCompleted;
use Illuminate\Support\Facades\Log;

class LogStagRequest
{
    public function handle(StagRequestCompleted $event): void
    {
        Log::log($event->successful ? 'info' : 'warning', 'stag.request', [
            'method' => $event->method,
            'endpoint' => $event->endpoint,
            'status' => $event->status,
            'duration_ms' => $event->durationMs,
            'user_id' => $event->userId,
            'tool' => $event->tool,
        ]);
    }
}

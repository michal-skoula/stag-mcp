<?php

namespace App\Listeners;

use App\Events\StagRequestCompleted;
use App\Models\StagCall;
use Throwable;

class RecordStagRequest
{
    /**
     * A failed write is reported and dropped rather than failing the STAG call.
     */
    public function handle(StagRequestCompleted $event): void
    {
        if (! config('telemetry.enabled')) {
            return;
        }

        try {
            StagCall::create([
                'user_id' => $event->userId,
                'tool' => $event->tool,
                'method' => $event->method,
                'endpoint' => $event->endpoint,
                'status' => $event->status,
                'duration_ms' => $event->durationMs,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}

<?php

use App\Models\StagCall;
use App\Models\ToolCall;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Telemetry rows are kept for `telemetry.retention_days`, then deleted.
 */
Schedule::command('model:prune', ['--model' => [ToolCall::class, StagCall::class]])->daily();

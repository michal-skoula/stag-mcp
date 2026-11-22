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

/*
 * Hourly, because the job needs an account with a live STAG ticket and the
 * default ticket lasts 30 minutes: it has to catch one while it exists. The
 * command itself decides whether anything is due (weekly, daily from mid
 * September to mid October), so most runs end after reading a small index.
 */
Schedule::command('stag:refresh-teachers')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

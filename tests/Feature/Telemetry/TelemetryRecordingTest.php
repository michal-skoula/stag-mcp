<?php

use App\Models\StagCall;
use App\Models\ToolCall;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

function callTelemetryTool(User $user, string $name, array $arguments = []): void
{
    test()->withToken($user->createToken('mcp')->plainTextToken)->postJson(route('mcp.stag'), [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $name, 'arguments' => $arguments],
    ])->assertOk();
}

it('stores one row per tool call and per STAG call, with user ids only', function () {
    Http::fake(['stag-ws.zcu.cz/*' => Http::response(['items' => []])]);
    $user = User::factory()->create(['name' => 'Jana Nováková']);

    callTelemetryTool($user, 'list-budovy', ['city' => 'Plzeň']);

    $call = ToolCall::sole();
    expect($call->user_id)->toBe($user->id)
        ->and($call->tool)->toBe('list-budovy')
        ->and($call->failed)->toBeFalse();

    $stag = StagCall::sole();
    expect($stag->user_id)->toBe($user->id)
        ->and($stag->tool)->toBe('list-budovy')
        ->and($stag->endpoint)->toBe('mistnost/getBudovy')
        ->and($stag->status)->toBe(200);

    expect(json_encode([$call->getAttributes(), $stag->getAttributes()]))
        ->not->toContain('Jana')
        ->not->toContain('Plzeň');
});

it('records a failed STAG call with its status', function () {
    Http::fake(['stag-ws.zcu.cz/*' => Http::response('boom', 500)]);

    callTelemetryTool(User::factory()->create(), 'list-budovy');

    expect(StagCall::sole()->status)->toBe(500)
        ->and(ToolCall::sole()->failed)->toBeTrue();
});

it('stores nothing when telemetry is disabled', function () {
    config(['telemetry.enabled' => false]);
    Http::fake(['stag-ws.zcu.cz/*' => Http::response(['items' => []])]);

    callTelemetryTool(User::factory()->create(), 'list-budovy');

    expect(ToolCall::count())->toBe(0)->and(StagCall::count())->toBe(0);
});

it('keeps the call working when the telemetry write fails', function () {
    Http::fake(['stag-ws.zcu.cz/*' => Http::response(['items' => []])]);
    Schema::drop('tool_calls');

    callTelemetryTool(User::factory()->create(), 'list-budovy');

    expect(StagCall::count())->toBe(1);
});

it('prunes rows past the retention window and keeps newer ones', function () {
    config(['telemetry.retention_days' => 90]);
    $old = ToolCall::factory()->create(['created_at' => now()->subDays(91)]);
    $recent = ToolCall::factory()->create(['created_at' => now()->subDays(89)]);
    $oldStag = StagCall::factory()->create(['created_at' => now()->subDays(91)]);

    Artisan::call('model:prune', ['--model' => [ToolCall::class, StagCall::class]]);

    expect(ToolCall::pluck('id')->all())->toBe([$recent->id])
        ->and(StagCall::find($oldStag->id))->toBeNull()
        ->and(ToolCall::find($old->id))->toBeNull();
});

it('keeps the rows and drops the user link when a user is deleted', function () {
    $user = User::factory()->create();
    ToolCall::factory()->create(['user_id' => $user->id]);

    $user->delete();

    expect(ToolCall::sole()->user_id)->toBeNull();
});

it('lists tools that were never called in the report', function () {
    ToolCall::factory()->count(2)->create(['tool' => 'who-am-i', 'user_id' => User::factory()->create()->id]);

    $this->artisan('telemetry:report', ['--days' => 7])
        ->expectsOutputToContain('Never called:')
        ->expectsOutputToContain('Active users: 1')
        ->assertSuccessful();
});

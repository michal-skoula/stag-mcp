<?php

use App\Models\User;

it('hands the inspector a bearer token for the only user', function () {
    $user = User::factory()->withStagToken()->create();

    $this->artisan('mcp:inspect', ['--print' => true])
        ->expectsOutputToContain("'--header' 'Authorization: Bearer ")
        ->assertSuccessful();

    expect($user->tokens()->pluck('name')->all())->toBe(['MCP Inspector']);
});

it('points the inspector at the STAG server route', function () {
    User::factory()->withStagToken()->create();

    $this->artisan('mcp:inspect', ['--print' => true])
        ->expectsOutputToContain("'--server-url' '".route('mcp.stag')."'")
        ->assertSuccessful();
});

it('mints the token under a name of its own so other clients keep theirs', function () {
    $user = User::factory()->create();
    $user->createToken('Claude Code');

    $this->artisan('mcp:inspect', ['--print' => true])->assertSuccessful();

    expect($user->tokens()->pluck('name')->all())->toBe(['Claude Code', 'MCP Inspector']);
});

it('replaces its own token rather than stacking them', function () {
    $user = User::factory()->create();

    $this->artisan('mcp:inspect', ['--print' => true])->assertSuccessful();
    $this->artisan('mcp:inspect', ['--print' => true])->assertSuccessful();

    expect($user->tokens()->where('name', 'MCP Inspector')->count())->toBe(1);
});

it('inspects as the user given by email when several exist', function () {
    User::factory()->create(['email' => 'jana@example.com']);
    $petr = User::factory()->create(['email' => 'petr@example.com']);

    $this->artisan('mcp:inspect', ['--user' => 'petr@example.com', '--print' => true])
        ->expectsOutputToContain('petr@example.com')
        ->assertSuccessful();

    expect($petr->tokens()->count())->toBe(1);
});

it('fails when the email given matches nobody', function () {
    User::factory()->create(['email' => 'jana@example.com']);

    $this->artisan('mcp:inspect', ['--user' => 'nikdo@example.com', '--print' => true])
        ->expectsOutputToContain('No user is registered with the email nikdo@example.com')
        ->assertFailed();
});

it('warns when the user has not authorized IS-STAG', function () {
    User::factory()->create();

    $this->artisan('mcp:inspect', ['--print' => true])
        ->expectsOutputToContain('no live IS-STAG token')
        ->assertSuccessful();
});

it('stays quiet about IS-STAG when the user is authorized', function () {
    User::factory()->withStagToken()->create();

    $this->artisan('mcp:inspect', ['--print' => true])
        ->doesntExpectOutputToContain('no live IS-STAG token')
        ->assertSuccessful();
});

it('fails when no users exist', function () {
    $this->artisan('mcp:inspect', ['--print' => true])
        ->expectsOutputToContain('No users exist yet')
        ->assertFailed();
});

it('defaults --cli to listing tools in json', function () {
    User::factory()->create();

    // Laravel's expectsOutputToContain assertions each latch onto a separate
    // console write, so two of them checking the same single printed line
    // race for it and only the first ever gets credited — hence one
    // assertion per test here, each covering a contiguous chunk of that line.
    $this->artisan('mcp:inspect', ['--print' => true, '--cli' => true])
        ->expectsOutputToContain("'--cli' '--transport' 'http' '--server-url'")
        ->assertSuccessful();
});

it('formats --cli output as json by default', function () {
    User::factory()->create();

    $this->artisan('mcp:inspect', ['--print' => true, '--cli' => true])
        ->expectsOutputToContain("'--format' 'json' '--method' 'tools/list'")
        ->assertSuccessful();
});

it('calls a named tool with its arguments in --cli mode', function () {
    User::factory()->create();

    $this->artisan('mcp:inspect', [
        '--print' => true,
        '--cli' => true,
        '--tool' => 'get-predmet-info',
        '--tool-arg' => ['katedra=KIV', 'zkratka=UPA'],
    ])
        ->expectsOutputToContain(
            "'--method' 'tools/call' '--tool-name' 'get-predmet-info' '--tool-arg' 'katedra=KIV' '--tool-arg' 'zkratka=UPA'"
        )
        ->assertSuccessful();
});

it('honours an explicit --method in --cli mode when no tool is given', function () {
    User::factory()->create();

    $this->artisan('mcp:inspect', ['--print' => true, '--cli' => true, '--method' => 'resources/list'])
        ->expectsOutputToContain("'--method' 'resources/list'")
        ->assertSuccessful();
});

it('suppresses the inspecting banner in --cli mode', function () {
    User::factory()->create();

    $this->artisan('mcp:inspect', ['--print' => true, '--cli' => true])
        ->doesntExpectOutputToContain('Inspecting')
        ->doesntExpectOutputToContain('no live IS-STAG token')
        ->assertSuccessful();
});

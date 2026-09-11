<?php

use App\Models\User;

it('mints a token for the only user and prints the export line', function () {
    $user = User::factory()->create(['email' => 'jana@example.com']);

    $this->artisan('mcp:token')
        ->expectsOutputToContain('jana@example.com')
        ->expectsOutputToContain('export STAG_MCP_TOKEN=')
        ->assertSuccessful();

    expect($user->tokens()->pluck('name')->all())->toBe(['Claude Code']);
});

it('names the token after the client given', function () {
    $user = User::factory()->create();

    $this->artisan('mcp:token', ['name' => 'OpenWebUI'])->assertSuccessful();

    expect($user->tokens()->pluck('name')->all())->toBe(['OpenWebUI']);
});

it('picks the user by email when several exist', function () {
    User::factory()->create(['email' => 'jana@example.com']);
    $petr = User::factory()->create(['email' => 'petr@example.com']);

    $this->artisan('mcp:token', ['--user' => 'petr@example.com'])->assertSuccessful();

    expect($petr->tokens()->count())->toBe(1);
});

it('replaces a token of the same name rather than stacking them', function () {
    $user = User::factory()->create();
    $user->createToken('Claude Code');

    $this->artisan('mcp:token')->assertSuccessful();

    expect($user->tokens()->count())->toBe(1);
});

it('warns when the user has not authorized IS-STAG', function () {
    User::factory()->create();

    $this->artisan('mcp:token')
        ->expectsOutputToContain('no live IS-STAG token')
        ->assertSuccessful();
});

it('stays quiet about IS-STAG when the user is authorized', function () {
    User::factory()->withStagToken()->create();

    $this->artisan('mcp:token')
        ->doesntExpectOutputToContain('no live IS-STAG token')
        ->assertSuccessful();
});

it('fails when no users exist', function () {
    $this->artisan('mcp:token')
        ->expectsOutputToContain('No users exist yet')
        ->assertFailed();
});

<?php

use App\Models\User;

/**
 * The MCP route carries no session, so the bearer token is the only way a client
 * can identify itself. Every tool reads the STAG ticket off the resolved user.
 */
function initializeRequest(): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'pest', 'version' => '1.0'],
        ],
    ];
}

it('rejects a request that carries no bearer token', function () {
    $this->postJson(route('mcp.stag'), initializeRequest())
        ->assertUnauthorized();
});

it('advertises bearer authentication when it rejects a request', function () {
    $this->postJson(route('mcp.stag'), initializeRequest())
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", error="invalid_token"');
});

it('rejects a token that does not exist', function () {
    $this->withToken('1|nonsense')
        ->postJson(route('mcp.stag'), initializeRequest())
        ->assertUnauthorized();
});

it('accepts a valid token and resolves the owning user', function () {
    $user = User::factory()->create(['name' => 'Jana Nováková']);
    $token = $user->createToken('mcp')->plainTextToken;

    $this->withToken($token)
        ->postJson(route('mcp.stag'), initializeRequest())
        ->assertOk();

    expect(auth('sanctum')->user()->is($user))->toBeTrue();
});

it('stops accepting a token once it is revoked', function () {
    $user = User::factory()->create();
    $token = $user->createToken('mcp')->plainTextToken;

    $user->tokens()->delete();

    $this->withToken($token)
        ->postJson(route('mcp.stag'), initializeRequest())
        ->assertUnauthorized();
});

<?php

use App\Models\User;
use Livewire\Livewire;

it('mints a named token and shows the plaintext value once', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test('mcp-token')
        ->set('name', 'Claude Code')
        ->call('generate')
        ->assertSet('name', '');

    expect($user->tokens()->pluck('name')->all())->toBe(['Claude Code']);

    $component->assertSee($component->get('plainTextToken'));
});

it('keeps existing tokens when another client is added', function () {
    $user = User::factory()->create();
    $user->createToken('Claude Code');

    Livewire::actingAs($user)
        ->test('mcp-token')
        ->set('name', 'OpenWebUI')
        ->call('generate');

    expect($user->tokens()->pluck('name')->sort()->values()->all())
        ->toBe(['Claude Code', 'OpenWebUI']);
});

it('requires a client name', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('mcp-token')
        ->set('name', '')
        ->call('generate')
        ->assertHasErrors(['name' => 'required']);

    expect($user->tokens()->count())->toBe(0);
});

it('lists the tokens with the client they belong to', function () {
    $user = User::factory()->create();
    $user->createToken('Claude Code');
    $user->createToken('OpenWebUI');

    Livewire::actingAs($user)
        ->test('mcp-token')
        ->assertSee('Claude Code')
        ->assertSee('OpenWebUI')
        ->assertSee('Never used');
});

it('revokes one token and leaves the others working', function () {
    $user = User::factory()->create();
    $revoked = $user->createToken('OpenWebUI')->accessToken;
    $kept = $user->createToken('Claude Code')->accessToken;

    Livewire::actingAs($user)
        ->test('mcp-token')
        ->call('revoke', $revoked->getKey());

    expect($user->tokens()->pluck('name')->all())->toBe(['Claude Code'])
        ->and($user->tokens()->whereKey($kept->getKey())->exists())->toBeTrue();
});

it('refuses to revoke a token belonging to someone else', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $victim = $other->createToken('Claude Code')->accessToken;

    Livewire::actingAs($user)
        ->test('mcp-token')
        ->call('revoke', $victim->getKey());

    expect($other->tokens()->count())->toBe(1);
});

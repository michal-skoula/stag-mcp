<?php

use App\Models\User;
use Livewire\Livewire;

it('redirects a guest to login', function () {
    $this->get(route('settings'))->assertRedirect(route('login'));
});

it('renders for an authenticated user', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings'))
        ->assertOk()
        ->assertSeeLivewire('settings-city');
});

it('prefills the city from existing preferences', function () {
    $user = User::factory()->create();
    $user->preferences()->create(['city' => 'Plzeň']);

    Livewire::actingAs($user)
        ->test('settings-city')
        ->assertSet('city', 'Plzeň');
});

it('creates preferences on first save', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('settings-city')
        ->set('city', 'Plzeň')
        ->call('save')
        ->assertSet('saved', true);

    // mount() lazy-loads (and caches) a null preferences relation on this same
    // $user instance before save() creates the row, so re-fetch rather than
    // reading the stale cached relation.
    expect($user->refresh()->preferences->city)->toBe('Plzeň');
});

it('updates existing preferences instead of duplicating them', function () {
    $user = User::factory()->create();
    $user->preferences()->create(['city' => 'Plzeň']);

    Livewire::actingAs($user)
        ->test('settings-city')
        ->set('city', 'Cheb')
        ->call('save');

    expect($user->preferences()->count())->toBe(1)
        ->and($user->preferences->fresh()->city)->toBe('Cheb');
});

it('rejects a city longer than 255 characters', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('settings-city')
        ->set('city', str_repeat('a', 256))
        ->call('save')
        ->assertHasErrors(['city' => 'max']);

    expect($user->preferences)->toBeNull();
});

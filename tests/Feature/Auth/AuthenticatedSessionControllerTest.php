<?php

use App\Models\User;

it('renders the login form', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Log in');
});

it('signs the user in and redirects to the dashboard', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('dashboard'));
});

it('redirects to the page the guest originally requested', function () {
    $user = User::factory()->create();

    $this->get(route('dashboard'));

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard'));
});

it('rejects a wrong password with the failed credentials message', function () {
    $user = User::factory()->create();

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'not-the-password',
    ])->assertSessionHasErrors(['email' => trans('auth.failed')]);

    $this->assertGuest();
});

it('rejects an email that belongs to no account', function () {
    $this->post(route('login'), [
        'email' => 'nobody@example.com',
        'password' => 'password',
    ])->assertSessionHasErrors(['email' => trans('auth.failed')]);

    $this->assertGuest();
});

it('rejects an empty payload', function () {
    $this->post(route('login'), [])
        ->assertSessionHasErrors(['email', 'password']);

    $this->assertGuest();
});

it('persists a remember token when remember me is checked', function () {
    $user = User::factory()->create(['remember_token' => null]);

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
        'remember' => 'on',
    ]);

    expect($user->fresh()->remember_token)->not->toBeNull();
});

it('does not persist a remember token when remember me is left unchecked', function () {
    $user = User::factory()->create(['remember_token' => null]);

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    expect($user->fresh()->remember_token)->toBeNull();
});

it('logs the user out and sends them back to the home page', function () {
    $response = $this->actingAs(User::factory()->create())
        ->post(route('logout'));

    $this->assertGuest();
    $response->assertRedirect('/');
});

it('redirects a guest from the dashboard to the login form', function () {
    $this->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

it('redirects an already authenticated user away from the login form', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('login'))
        ->assertRedirect(route('dashboard'));
});

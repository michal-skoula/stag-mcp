<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('renders the registration form', function () {
    $this->get(route('register'))
        ->assertOk()
        ->assertSee('Create an account');
});

it('creates the user, signs them in and redirects to the dashboard', function () {
    $response = $this->post(route('register'), [
        'name' => 'Jana Nováková',
        'email' => 'jana@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ]);

    $user = User::firstWhere('email', 'jana@example.com');

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Jana Nováková');

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('dashboard'));
});

it('stores the password hashed rather than in plain text', function () {
    $this->post(route('register'), [
        'name' => 'Jana Nováková',
        'email' => 'jana@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ]);

    $user = User::firstWhere('email', 'jana@example.com');

    expect($user->password)->not->toBe('correct-horse-battery')
        ->and(Hash::check('correct-horse-battery', $user->password))->toBeTrue();
});

it('rejects an empty payload and keeps the visitor a guest', function () {
    $this->post(route('register'), [])
        ->assertSessionHasErrors(['name', 'email', 'password']);

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('rejects an email that is already registered', function () {
    User::factory()->create(['email' => 'jana@example.com']);

    $this->post(route('register'), [
        'name' => 'Jana Nováková',
        'email' => 'jana@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertSessionHasErrors('email');

    expect(User::where('email', 'jana@example.com')->count())->toBe(1);
});

it('rejects a password that does not match its confirmation', function () {
    $this->post(route('register'), [
        'name' => 'Jana Nováková',
        'email' => 'jana@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'something-else-entirely',
    ])->assertSessionHasErrors('password');

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('redirects an already authenticated user away from the registration form', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('register'))
        ->assertRedirect(route('dashboard'));
});

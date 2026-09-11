<?php

use App\Models\User;
use Illuminate\Support\Carbon;

it('stores the ticket and a thirty minute expiry by default', function () {
    Carbon::setTestNow('2026-09-11 12:00:00');
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('stag.authorize', ['stagUserTicket' => 'a-ticket']))
        ->assertOk();

    $user->refresh();

    expect($user->stag_token)->toBe('a-ticket')
        ->and($user->stag_token_valid_until->toDateTimeString())->toBe('2026-09-11 12:30:00');
});

it('stores a ninety day expiry when a long ticket was requested', function () {
    Carbon::setTestNow('2026-09-11 12:00:00');
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('stag.authorize', ['stagUserTicket' => 'a-ticket', 'long' => 1]))
        ->assertOk();

    expect($user->refresh()->stag_token_valid_until->toDateTimeString())
        ->toBe('2026-12-10 12:00:00');
});

it('requires a ticket', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('stag.authorize'))
        ->assertSessionHasErrors('stagUserTicket');
});

it('clears the ticket and its expiry on revoke', function () {
    $user = User::factory()->withStagToken()->create();

    $this->actingAs($user)
        ->post(route('stag.revoke'))
        ->assertNoContent();

    $user->refresh();

    expect($user->stag_token)->toBeNull()
        ->and($user->stag_token_valid_until)->toBeNull();
});

it('turns guests away', function () {
    $this->get(route('stag.authorize', ['stagUserTicket' => 'a-ticket']))
        ->assertRedirect(route('login'));
});

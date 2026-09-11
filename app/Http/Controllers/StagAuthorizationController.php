<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class StagAuthorizationController extends Controller
{
    /** @var int Lifetime STAG gives a standard ticket. */
    private const int TICKET_MINUTES = 30;

    /** @var int Lifetime STAG gives a ticket requested with longTicket=1. */
    private const int LONG_TICKET_DAYS = 90;

    /**
     * Callback for STAG, saves the token in the database.
     *
     * STAG does not report when a ticket expires, so the lifetime is inferred from
     * the "long" flag the authorization component appends to this callback URL.
     */
    public function authorize(#[CurrentUser] User $user, Request $request): View
    {
        $validated = $request->validate([
            'stagUserTicket' => ['string', 'required'],
            'long' => ['boolean', 'nullable'],
        ]);

        // todo: add "fail" route showing an auth failed view

        $user->update([
            'stag_token' => $validated['stagUserTicket'],
            'stag_token_valid_until' => $this->expiresAt((bool) ($validated['long'] ?? false)),
        ]);

        return view('auth.stag.success', [
            'token' => $validated['stagUserTicket'],
        ]);
    }

    /**
     * Removes the saved token from the database.
     */
    public function revoke(#[CurrentUser] User $user): Response
    {
        $user->update([
            'stag_token' => null,
            'stag_token_valid_until' => null,
        ]);

        return response(status: 204);
    }

    private function expiresAt(bool $longTicket): Carbon
    {
        return $longTicket
            ? now()->addDays(self::LONG_TICKET_DAYS)
            : now()->addMinutes(self::TICKET_MINUTES);
    }
}

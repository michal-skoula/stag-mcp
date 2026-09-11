<?php

namespace App\Console\Concerns;

use App\Models\User;

use function Laravel\Prompts\select;

/**
 * Picks the account a minted bearer token belongs to.
 *
 * Tokens are per user because every tool reads the STAG ticket off the resolved
 * user, so a command that mints one has to know whose it is. The command using
 * this must declare a `--user` option.
 */
trait ResolvesMcpUser
{
    /**
     * The user named by `--user`, the only user, or one picked from a list.
     *
     * Reports why it came up empty, so callers can just return a failure.
     */
    protected function resolveMcpUser(): ?User
    {
        if ($email = $this->option('user')) {
            $user = User::where('email', $email)->first();

            if ($user === null) {
                $this->components->error("No user is registered with the email {$email}.");
            }

            return $user;
        }

        $users = User::orderBy('id')->get();

        if ($users->isEmpty()) {
            $this->components->error('No users exist yet. Register one first.');

            return null;
        }

        if ($users->count() === 1) {
            return $users->first();
        }

        return $users->firstWhere('email', select(
            label: 'Which user is this for?',
            options: $users->pluck('email', 'email')->all(),
        ));
    }
}

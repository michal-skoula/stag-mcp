<?php

namespace App\Console\Commands;

use App\Clients\StagHttpClient;
use App\Models\User;
use App\Services\Teachers\TeacherDirectory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes the cached teacher directory in the background, so no user waits
 * on the slow REK fetch (about 1.7 s and 80 MB, against 0.1 s for a department).
 *
 * `getUciteleKatedry` refuses anonymous callers, so the job borrows the ticket
 * of any account that holds a live one. Default tickets last 30 minutes, so the
 * schedule runs hourly and piggybacks on whoever authorized recently; an
 * account with a long ticket makes it reliable. With no live ticket the job
 * does nothing and the next hourly run tries again. Meanwhile the on-demand
 * path with its stale fallback still works.
 */
class RefreshTeacherDirectoryCommand extends Command
{
    /** How many tickets to try before giving up, in case some are revoked early. */
    private const int MAX_TICKETS_TRIED = 3;

    protected $signature = 'stag:refresh-teachers
                            {--force : Refresh even if the snapshots are not old enough yet}';

    protected $description = 'Refresh the cached teacher directory (REK plus every cached department) ahead of expiry';

    public function handle(): int
    {
        $users = User::query()
            ->whereNotNull('stag_token')
            ->where(fn ($query) => $query->whereNull('stag_token_valid_until')->orWhere('stag_token_valid_until', '>', now()))
            ->orderByDesc('stag_token_valid_until')
            ->limit(self::MAX_TICKETS_TRIED)
            ->get();

        if ($users->isEmpty()) {
            $this->components->warn('No account holds a live STAG ticket, so nothing was refreshed.');

            return self::SUCCESS;
        }

        foreach ($users as $user) {
            $directory = new TeacherDirectory(new StagHttpClient($user));

            if (! $this->option('force') && ! $directory->isRefreshDue()) {
                $this->components->info('Snapshots are recent enough, nothing to do.');

                return self::SUCCESS;
            }

            $results = $directory->refreshAll();

            /* REK is first and aborts the run on failure, so a failed REK means try the next ticket. */
            if (($results[TeacherDirectory::WHOLE_UNIVERSITY] ?? false) === false) {
                continue;
            }

            $failed = array_keys(array_filter($results, fn (bool $ok) => ! $ok));
            $this->components->info('Refreshed '.(count($results) - count($failed)).' of '.count($results).' scopes.');

            if ($failed !== []) {
                Log::warning('Teacher directory refresh left old snapshots for: '.implode(', ', $failed));
                $this->components->warn('Kept the old snapshot for: '.implode(', ', $failed));

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        $this->components->error('STAG refused or failed the REK refresh with every ticket tried. Old snapshots are kept.');

        return self::FAILURE;
    }
}

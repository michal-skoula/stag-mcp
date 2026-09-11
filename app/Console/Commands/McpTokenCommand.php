<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

use function Laravel\Prompts\select;

class McpTokenCommand extends Command
{
    protected $signature = 'mcp:token
                            {name=Claude Code : Label for the client the token is for}
                            {--user= : Email of the user to mint for, when the app has more than one}';

    protected $description = 'Mint a bearer token for the STAG MCP server and print how to use it';

    public function handle(): int
    {
        $user = $this->resolveUser();

        if ($user === null) {
            $this->components->error('No users exist yet. Register one first.');

            return self::FAILURE;
        }

        $name = $this->argument('name');
        $user->tokens()->where('name', $name)->delete();

        $token = $user->createToken($name)->plainTextToken;

        $this->components->info("Minted \"{$name}\" for {$user->email}.");
        $this->line('');
        $this->line('  Export it so .mcp.json can pick it up, then restart Claude Code:');
        $this->line('');
        $this->line("  <fg=green>export STAG_MCP_TOKEN='{$token}'</>");
        $this->line('');

        if (! $user->hasValidStagToken()) {
            $this->components->warn(
                'This user has no live IS-STAG token. Tools needing one will say so until you authorize at '
                .route('dashboard').'.'
            );
        }

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        if ($email = $this->option('user')) {
            return User::where('email', $email)->first();
        }

        $users = User::orderBy('id')->get();

        return match (true) {
            $users->isEmpty() => null,
            $users->count() === 1 => $users->first(),
            default => $users->firstWhere('email', select(
                label: 'Which user is this token for?',
                options: $users->pluck('email', 'email')->all(),
            )),
        };
    }
}

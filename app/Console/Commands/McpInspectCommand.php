<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesMcpUser;
use App\Models\User;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Opens the MCP Inspector on /mcp/stag with a bearer token already attached.
 *
 * The `mcp:inspector` command from laravel/mcp opens the same UI but sends no
 * credentials, and the route sits behind auth:sanctum, so every call made there
 * dies on "No authenticated user" until a token is pasted into the inspector's
 * Authentication panel by hand. Handing the token over on the inspector's own
 * `--header` flag keeps the route as strict as it is in production, and the
 * tools run against a genuine user with a genuine STAG ticket.
 */
class McpInspectCommand extends Command
{
    use ResolvesMcpUser;

    protected $signature = 'mcp:inspect
                            {--user= : Email of the user to inspect as, when the app has more than one}
                            {--print : Print the inspector command instead of running it}';

    protected $description = 'Open the MCP Inspector on the STAG server, authenticated as a real user';

    /** Reused per run, so repeated inspecting does not pile up dead tokens. */
    private const string TOKEN_NAME = 'MCP Inspector';

    /** v1 accepts --header only in --cli mode; the web UI needs v2. */
    private const string INSPECTOR_PACKAGE = '@modelcontextprotocol/inspector@latest';

    public function handle(): int
    {
        $user = $this->resolveMcpUser();

        if ($user === null) {
            return self::FAILURE;
        }

        $process = $this->inspectorProcess($this->mintToken($user));

        $this->components->info("Inspecting {$this->serverUrl()} as {$user->email}.");

        if (! $user->hasValidStagToken()) {
            $this->components->warn(
                'This user has no live IS-STAG token. Tools needing one will say so until you authorize at '
                .route('dashboard').'.'
            );
        }

        if ($this->option('print')) {
            $this->line('');
            $this->line('  <fg=green>'.$process->getCommandLine().'</>');
            $this->line('');

            return self::SUCCESS;
        }

        return $this->runInspector($process);
    }

    private function mintToken(User $user): string
    {
        $user->tokens()->where('name', self::TOKEN_NAME)->delete();

        return $user->createToken(self::TOKEN_NAME)->plainTextToken;
    }

    private function inspectorProcess(string $token): Process
    {
        $process = new Process([
            'npx',
            '-y',
            self::INSPECTOR_PACKAGE,
            '--web',
            '--transport',
            'http',
            '--server-url',
            $this->serverUrl(),
            '--header',
            "Authorization: Bearer {$token}",
        ], base_path(), $this->environment());

        $process->setTimeout(null);

        return $process;
    }

    private function runInspector(Process $process): int
    {
        $process->run(fn (string $type, string $buffer) => $this->output->write($buffer));

        if (! $process->isSuccessful()) {
            $this->components->error('The inspector exited with an error. Is npx on your PATH?');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function serverUrl(): string
    {
        return route('mcp.stag');
    }

    /**
     * Node trusts no local certificate authority, so an https APP_URL needs the
     * same escape hatch laravel/mcp's own inspector command uses.
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        return str_starts_with($this->serverUrl(), 'https://')
            ? ['NODE_TLS_REJECT_UNAUTHORIZED' => '0']
            : [];
    }
}

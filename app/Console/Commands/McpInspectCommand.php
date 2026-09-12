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
 *
 * `--cli` swaps the browser UI for the inspector's own headless mode, so an
 * agent (or a human in a hurry) can call one tool and read its JSON result —
 * schema-validation errors included — without a browser in the loop.
 */
class McpInspectCommand extends Command
{
    use ResolvesMcpUser;

    protected $signature = 'mcp:inspect
                            {--user= : Email of the user to inspect as, when the app has more than one}
                            {--print : Print the inspector command instead of running it}
                            {--cli : Run the inspector headlessly instead of opening the web UI, for scripting or agent use}
                            {--method= : MCP method to invoke in --cli mode (default: tools/list, or tools/call when --tool is given)}
                            {--tool= : Tool name to call in --cli mode; implies --method=tools/call}
                            {--tool-arg=* : key=value tool argument for --cli mode, repeatable}
                            {--format=json : Output format in --cli mode: json or text}';

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

        // --cli output is meant to be read by a script or an agent, so it stays
        // limited to whatever the inspector itself prints on stdout — no banner.
        if (! $this->option('cli')) {
            $this->components->info("Inspecting {$this->serverUrl()} as {$user->email}.");

            if (! $user->hasValidStagToken()) {
                $this->components->warn(
                    'This user has no live IS-STAG token. Tools needing one will say so until you authorize at '
                    .route('dashboard').'.'
                );
            }
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
            ...$this->inspectorArgs($token),
        ], base_path(), $this->environment());

        $process->setTimeout(null);

        return $process;
    }

    /**
     * @return list<string>
     */
    private function inspectorArgs(string $token): array
    {
        $auth = ['--transport', 'http', '--server-url', $this->serverUrl(), '--header', "Authorization: Bearer {$token}"];

        if (! $this->option('cli')) {
            return ['--web', ...$auth];
        }

        $args = ['--cli', ...$auth, '--format', $this->option('format')];
        $args[] = '--method';

        if ($tool = $this->option('tool')) {
            $args[] = 'tools/call';
            $args[] = '--tool-name';
            $args[] = $tool;

            foreach ($this->option('tool-arg') as $pair) {
                $args[] = '--tool-arg';
                $args[] = $pair;
            }

            return $args;
        }

        $args[] = $this->option('method') ?? 'tools/list';

        return $args;
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

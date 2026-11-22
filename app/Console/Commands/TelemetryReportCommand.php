<?php

namespace App\Console\Commands;

use App\Mcp\Servers\StagMcpServer;
use App\Models\StagCall;
use App\Models\ToolCall;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use ReflectionClass;

class TelemetryReportCommand extends Command
{
    protected $signature = 'telemetry:report {--days=30 : How many days back to look}';

    protected $description = 'Print tool usage, STAG latency per endpoint and active users from the telemetry tables';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);

        $this->components->info("Usage over the last {$days} days");

        $counts = ToolCall::where('created_at', '>=', $since)
            ->selectRaw('tool, count(*) as calls, sum(failed) as failures, avg(duration_ms) as avg_ms')
            ->groupBy('tool')
            ->get()
            ->keyBy('tool');

        $rows = collect($this->registeredToolNames())
            ->merge($counts->keys())
            ->unique()
            ->map(fn (string $tool) => [
                $tool,
                (int) ($counts[$tool]->calls ?? 0),
                (int) ($counts[$tool]->failures ?? 0),
                isset($counts[$tool]) ? round((float) $counts[$tool]->avg_ms) : '-',
            ])
            ->sortByDesc(fn (array $row) => $row[1])
            ->values()
            ->all();

        $this->table(['Tool', 'Calls', 'Failed', 'Avg ms'], $rows);

        $unused = collect($rows)->where(1, 0)->pluck(0);
        $this->line($unused->isEmpty() ? 'Every registered tool was called.' : 'Never called: '.$unused->implode(', '));
        $this->newLine();

        $endpoints = StagCall::where('created_at', '>=', $since)
            ->selectRaw('endpoint, count(*) as calls, sum(case when status is null or status >= 400 then 1 else 0 end) as errors, avg(duration_ms) as avg_ms, max(duration_ms) as max_ms')
            ->groupBy('endpoint')
            ->orderByDesc('calls')
            ->get()
            ->map(fn ($row) => [$row->endpoint, (int) $row->calls, (int) $row->errors, round((float) $row->avg_ms), round((float) $row->max_ms)])
            ->all();

        $this->table(['STAG endpoint', 'Calls', 'Errors', 'Avg ms', 'Max ms'], $endpoints);
        $this->newLine();

        $active = ToolCall::where('created_at', '>=', $since)->whereNotNull('user_id')->distinct()->count('user_id');
        $this->line("Active users: {$active}");

        $busiest = ToolCall::where('created_at', '>=', $since)
            ->whereNotNull('user_id')
            ->select('user_id', DB::raw('count(*) as calls'))
            ->groupBy('user_id')
            ->orderByDesc('calls')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [$row->user_id, (int) $row->calls])
            ->all();

        $this->table(['User id', 'Calls'], $busiest);

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function registeredToolNames(): array
    {
        $classes = (new ReflectionClass(StagMcpServer::class))->getDefaultProperties()['tools'] ?? [];

        return array_values(array_map(fn (string $class) => app($class)->name(), $classes));
    }
}

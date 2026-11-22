<?php

use App\Contracts\IssueReportDestination;
use App\Mcp\Servers\StagMcpServer;
use App\Mcp\Tools\ReportIssueTool;
use App\Models\IssueReport;
use App\Models\User;
use App\Services\Reports\IssueReportData;
use App\Services\Reports\IssueReportManager;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

function reportIssue(User $user, array $arguments): mixed
{
    return StagMcpServer::actingAs($user)->tool(ReportIssueTool::class, $arguments);
}

it('stores a report in the database by default', function () {
    $user = User::factory()->create();

    reportIssue($user, [
        'description' => 'Grades came back empty for this semester.',
        'tool' => 'get-znamky',
        'error' => 'IS-STAG returned an unexpected HTTP 500.',
        'arguments' => ['rok' => 2026, 'semestr' => 'ZS'],
    ])->assertOk()->assertSee('"filed":true');

    $report = IssueReport::sole();
    expect($report->user_id)->toBe($user->id)
        ->and($report->tool)->toBe('get-znamky')
        ->and($report->description)->toBe('Grades came back empty for this semester.')
        ->and($report->error)->toBe('IS-STAG returned an unexpected HTTP 500.')
        ->and($report->arguments)->toBe(['rok' => 2026, 'semestr' => 'ZS'])
        ->and($report->occurred_at)->not->toBeNull();
});

it('strips personal data before it reaches the destination', function () {
    $user = User::factory()->withStagToken('a-ticket')->create(['name' => 'Jana Nováková', 'email' => 'jana@students.zcu.cz']);

    reportIssue($user, [
        'description' => 'A25B0093P sees nothing, mail jana@students.zcu.cz',
        'error' => 'failed for osCislo=A25B0093P',
        'arguments' => ['osCislo' => 'A25B0093P', 'grade' => '1', 'rok' => 2026],
    ])->assertOk();

    $stored = json_encode(IssueReport::sole()->getAttributes());
    expect($stored)
        ->not->toContain('A25B0093P')
        ->not->toContain('jana@students')
        ->not->toContain('Nováková')
        ->not->toContain('a-ticket')
        ->toContain('2026');
});

it('rejects a report without a description or with an oversized one', function (array $arguments) {
    reportIssue(User::factory()->create(), $arguments)->assertHasErrors();

    expect(IssueReport::count())->toBe(0);
})->with([
    'missing' => [[]],
    'empty' => [['description' => '']],
    'too long' => [['description' => str_repeat('a', 2001)]],
]);

it('limits how many reports one user can file per hour', function () {
    config(['issue-reports.max_per_hour' => 2]);
    $user = User::factory()->create();

    reportIssue($user, ['description' => 'one'])->assertOk();
    reportIssue($user, ['description' => 'two'])->assertOk();
    reportIssue($user, ['description' => 'three'])->assertHasErrors()->assertSee('Report limit reached');

    expect(IssueReport::count())->toBe(2);

    auth()->forgetGuards();
    $other = User::factory()->create();
    reportIssue($other, ['description' => 'fine'])->assertOk();
});

it('does not count a failed delivery against the limit', function () {
    Exceptions::fake();
    $user = User::factory()->create();
    app()->bind(IssueReportDestination::class, fn () => new class implements IssueReportDestination
    {
        public function submit(IssueReportData $report): void
        {
            throw new RuntimeException('destination down');
        }
    });

    reportIssue($user, ['description' => 'broken'])->assertHasErrors()->assertSee('could not be filed');

    expect(RateLimiter::attempts("issue-report:{$user->id}"))->toBe(0);
    Exceptions::assertReported(RuntimeException::class);
});

it('sends the report to a custom driver chosen in config', function () {
    $received = new ArrayObject;
    app(IssueReportManager::class)->extend('memory', fn () => new class($received) implements IssueReportDestination
    {
        public function __construct(private ArrayObject $received) {}

        public function submit(IssueReportData $report): void
        {
            $this->received[] = $report->description;
        }
    });
    config(['issue-reports.driver' => 'memory']);

    reportIssue(User::factory()->create(), ['description' => 'via driver'])->assertOk();

    expect($received->getArrayCopy())->toBe(['via driver'])->and(IssueReport::count())->toBe(0);
});

it('is advertised only when a destination is configured', function (?string $driver, bool $listed) {
    config(['issue-reports.driver' => $driver]);
    Http::fake();
    $token = User::factory()->create()->createToken('mcp')->plainTextToken;

    $names = collect(
        test()->withToken($token)->postJson(route('mcp.stag'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertOk()->json('result.tools')
    )->pluck('name');

    expect($names->contains('report-issue'))->toBe($listed);
})->with([
    'database' => ['database', true],
    'none' => ['none', false],
    'unset' => [null, false],
]);

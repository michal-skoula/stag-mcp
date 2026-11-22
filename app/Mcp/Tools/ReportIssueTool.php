<?php

namespace App\Mcp\Tools;

use App\Contracts\IssueReportDestination;
use App\Models\User;
use App\Services\Reports\IssueReportData;
use App\Services\Reports\IssueReportManager;
use App\Services\Reports\ReportSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('report-issue')]
#[Title('Report Issue')]
#[Description('Reports a problem with this server to its operator: a tool that failed unexpectedly, wrong or missing STAG data, or behaviour that looks like a bug. Only call it when the user asks to report something or agrees to it. Describe what went wrong in your own words. Never put student numbers, names, grades, tickets or other personal data in any field; known identifiers are stripped, but free text cannot be checked fully.')]
class ReportIssueTool extends Tool
{
    /**
     * Hidden from MCP clients when the operator set the destination to `none`,
     * so a self-hosted install that does not want reports never advertises it.
     */
    public function shouldRegister(IssueReportManager $reports): bool
    {
        return $reports->isEnabled();
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'description' => $schema->string()
                ->description('What the user was trying to do and what went wrong. No personal data.')
                ->max(2000)
                ->required(),
            'tool' => $schema->string()
                ->description('Name of the tool that misbehaved, if one did, e.g. "get-znamky".')
                ->max(100),
            'error' => $schema->string()
                ->description('The error message the tool returned, if there was one.')
                ->max(2000),
            'arguments' => $schema->object()
                ->description('The arguments the tool was called with, as flat key/value pairs. Nested values, and values under keys that look personal, are dropped.'),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'filed' => $schema->boolean()->description('Whether the report was stored.'),
            'message' => $schema->string()->description('Plain-language outcome.'),
        ];
    }

    /**
     * Handle the tool request.
     */
    public function handle(
        Request $request,
        IssueReportDestination $destination,
        ReportSanitizer $sanitizer,
        #[CurrentUser('sanctum')] ?User $user = null,
    ): ResponseFactory|Response {
        if ($user === null) {
            return Response::error('No authenticated user. The MCP client must send a bearer token.');
        }

        $input = $request->validate([
            'description' => ['required', 'string', 'max:2000'],
            'tool' => ['nullable', 'string', 'max:100'],
            'error' => ['nullable', 'string', 'max:2000'],
            'arguments' => ['nullable', 'array'],
        ]);

        $limitKey = "issue-report:{$user->id}";
        $limit = (int) config('issue-reports.max_per_hour');

        if (RateLimiter::tooManyAttempts($limitKey, $limit)) {
            $minutes = (int) ceil(RateLimiter::availableIn($limitKey) / 60);

            return Response::error("Report limit reached ({$limit} per hour). Try again in about {$minutes} minutes.");
        }

        $report = new IssueReportData(
            userId: $user->id,
            description: $sanitizer->text($input['description']),
            tool: isset($input['tool']) ? $sanitizer->text($input['tool']) : null,
            error: isset($input['error']) ? $sanitizer->text($input['error']) : null,
            arguments: $sanitizer->arguments($input['arguments'] ?? []),
            occurredAt: CarbonImmutable::now(),
        );

        try {
            $destination->submit($report);
        } catch (Throwable $e) {
            report($e);

            return Response::error('The report could not be filed right now. Tell the user to contact the operator directly.');
        }

        RateLimiter::hit($limitKey, 3600);

        return Response::structured([
            'filed' => true,
            'message' => 'Report filed. Thank you.',
        ]);
    }
}

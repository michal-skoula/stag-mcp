<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;

/**
 * One problem report, as handed to a destination. Every free-text field has
 * already been through {@see ReportSanitizer}.
 */
final readonly class IssueReportData
{
    /**
     * @param  array<string, scalar|null>  $arguments  Scalar tool arguments, personal keys blanked.
     */
    public function __construct(
        public ?int $userId,
        public string $description,
        public ?string $tool,
        public ?string $error,
        public array $arguments,
        public CarbonImmutable $occurredAt,
    ) {}
}

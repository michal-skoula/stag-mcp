<?php

namespace App\Services\Reports;

use App\Contracts\IssueReportDestination;
use App\Models\IssueReport;

class DatabaseIssueReportDestination implements IssueReportDestination
{
    public function submit(IssueReportData $report): void
    {
        IssueReport::create([
            'user_id' => $report->userId,
            'tool' => $report->tool,
            'description' => $report->description,
            'error' => $report->error,
            'arguments' => $report->arguments === [] ? null : $report->arguments,
            'occurred_at' => $report->occurredAt,
        ]);
    }
}

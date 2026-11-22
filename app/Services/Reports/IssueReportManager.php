<?php

namespace App\Services\Reports;

use App\Contracts\IssueReportDestination;
use Illuminate\Support\Manager;

/**
 * Resolves the configured report destination. Built on Laravel's Manager so a
 * driver is added with `extend()` and chosen by `issue-reports.driver`.
 */
class IssueReportManager extends Manager
{
    /**
     * Whether a destination is configured. `none` or an empty value turns the
     * report-issue tool off.
     */
    public function isEnabled(): bool
    {
        return ! in_array($this->getDefaultDriver(), [null, '', 'none'], true);
    }

    public function getDefaultDriver(): ?string
    {
        return $this->config->get('issue-reports.driver');
    }

    protected function createDatabaseDriver(): IssueReportDestination
    {
        return new DatabaseIssueReportDestination;
    }
}

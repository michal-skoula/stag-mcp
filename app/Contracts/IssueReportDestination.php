<?php

namespace App\Contracts;

use App\Services\Reports\IssueReportData;

/**
 * Somewhere a problem report can be sent. The report-issue tool only knows this
 * interface, so a new destination is a new class and a config value.
 */
interface IssueReportDestination
{
    /**
     * Stores or forwards one report. The data has already been scrubbed of
     * tickets, student numbers and other personal identifiers.
     *
     * @throws \Throwable when the report could not be delivered
     */
    public function submit(IssueReportData $report): void;
}

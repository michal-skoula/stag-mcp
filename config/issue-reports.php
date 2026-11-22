<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Issue report destination
    |--------------------------------------------------------------------------
    |
    | Where problems reported through the `report-issue` MCP tool end up.
    |
    |   database  Rows in the `issue_reports` table. The default, needs no credentials.
    |   none      Switches the tool off. It is not advertised to MCP clients at all.
    |
    | Any other name is a custom driver, registered from a service provider with
    | `app(IssueReportManager::class)->extend('name', fn () => new MyDestination)`
    | where MyDestination implements App\Contracts\IssueReportDestination. A
    | GitHub issues driver would be added that way, with its token in config.
    |
    */

    'driver' => env('ISSUE_REPORT_DRIVER', 'database'),

    /*
    | How many reports one user may file per hour. Stops an agent stuck in a loop
    | from filling the destination.
    */

    'max_per_hour' => (int) env('ISSUE_REPORT_MAX_PER_HOUR', 5),

];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Usage telemetry
    |--------------------------------------------------------------------------
    |
    | When enabled, each MCP tool call and each STAG call is stored in the
    | `tool_calls` and `stag_calls` tables, so the operator can see which tools
    | are used, how fast STAG answers per endpoint and how many users are active.
    |
    | What is stored: the user's id, the tool name, the STAG endpoint path (never
    | its query string), the HTTP status, the duration and a timestamp. What is
    | never stored: names, osCislo, tickets, tool arguments or results.
    |
    | Operators who run this for other people should tell them it is collected.
    |
    */

    'enabled' => (bool) env('TELEMETRY_ENABLED', true),

    /*
    | Rows older than this are deleted by the scheduled `model:prune` run.
    */

    'retention_days' => (int) env('TELEMETRY_RETENTION_DAYS', 90),

];

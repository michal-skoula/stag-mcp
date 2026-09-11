# IS-STAG MCP

An MCP server exposing [IS-STAG](https://is-stag.zcu.cz) to AI clients, built on
[Laravel MCP](https://laravel.com/docs/mcp).

STAG authorization happens once in the browser and the resulting ticket is stored
against your account, so clients never handle it. Clients authenticate to this app
with a Sanctum bearer token instead.

## Setup

```bash
composer setup
composer dev
```

`composer dev` runs the app on <http://127.0.0.1:8000>, and the MCP endpoint is a
route on it at `/mcp/stag`. There is no separate process to start.

Then, in the browser:

1. Register at `/register`.
2. On the dashboard, click **Authorize** under *Authorize IS-STAG*. Tick
   **Keep token valid for longer** unless you enjoy re-authorizing, because a
   standard STAG ticket dies after 30 minutes while a long one lasts 90 days.

## Connecting Claude Code

`.mcp.json` is committed and already points at the local server. It reads the
bearer token from your environment, so nothing secret is in the repo:

```json
"is-stag": {
    "type": "http",
    "url": "${STAG_MCP_URL:-http://127.0.0.1:8000}/mcp/stag",
    "headers": { "Authorization": "Bearer ${STAG_MCP_TOKEN}" }
}
```

Mint a token and export it:

```bash
php artisan mcp:token
# export STAG_MCP_TOKEN='1|...'
```

Restart Claude Code afterwards, since `.mcp.json` is read at startup. Check it
connected with `/mcp`.

The dashboard has the same thing with a copy button, and takes a name per client
so you can revoke one without disturbing the others. `mcp:token "OpenWebUI"` does
that from the CLI.

## Authentication, both layers

Two credentials are involved and they are easy to confuse:

|                       | What it is                      | Where it lives                |
|-----------------------|---------------------------------|-------------------------------|
| MCP client → this app | Sanctum bearer token            | `personal_access_tokens`      |
| this app → STAG       | STAG ticket, sent as `WSCOOKIE` | `users.stag_token`, encrypted |

STAG never reports when a ticket expires, so `users.stag_token_valid_until` is our
own estimate from the login flow, and a tool checks it before spending a request.

## Writing a tool

Most of STAG's `rest2` surface answers anonymous callers, so a plain tool just
injects `StagClient` and calls it. For a service that demands a login, use the
trait and implement `handleForStagUser()` instead of `handle()`:

```php
class ListNotificationsTool extends Tool
{
    use RequiresStagLogin;

    protected function handleForStagUser(Request $request, StagClient $stag): ResponseFactory|Response
    {
        return Response::structured($stag->get('oznameni/list'));
    }
}
```

The trait resolves the user, refuses a missing or lapsed token with a message
saying how to fix it, and turns any `StagException` into a tool error.

Register the tool in `App\Mcp\Servers\StagMcpServer`.

## Testing

```bash
php artisan test        # everything
php artisan mcp:inspect # poke the server by hand in the MCP Inspector
```

`mcp:inspect` mints a token named *MCP Inspector*, then starts the inspector with
that token already in the `Authorization` header, so tools resolve a real user and
a real STAG ticket. Pass `--user` to pick the account and `--print` to get the
command without running it.

The `mcp:inspector` command from laravel/mcp opens the same UI on the same route
but sends no credentials, so every call there fails on *No authenticated user*
until you paste a token into its Authentication panel yourself.

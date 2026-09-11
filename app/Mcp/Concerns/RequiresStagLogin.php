<?php

namespace App\Mcp\Concerns;

use App\Clients\StagClient;
use App\Exceptions\StagException;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * For tools calling a STAG service that refuses anonymous callers.
 *
 * Most of the rest2 surface is public, so this is opt-in rather than the default.
 * A tool using it implements handleForStagUser() instead of handle(), and is handed
 * a client already bound to the caller.
 *
 * The tool stays advertised even when the caller has no ticket. Hiding it via
 * shouldRegister() would answer a call with "tool not found", and tickets expire
 * after 30 minutes, so a tool listed at the start of a session is routinely called
 * after its ticket has died.
 */
trait RequiresStagLogin
{
    /**
     * @internal use `handleForStagUser()` instead.
     */
    public function handle(Request $request, #[CurrentUser('sanctum')] ?User $user = null): ResponseFactory|Response
    {
        if ($user === null) {
            return Response::error('No authenticated user. The MCP client must send a bearer token.');
        }

        if (! $user->hasValidStagToken()) {
            return Response::error($user->stag_token === null
                ? StagException::notAuthorized()->getMessage()
                : StagException::ticketRejected()->getMessage());
        }

        try {
            return $this->handleForStagUser($request, new StagClient($user));
        } catch (StagException $e) {
            return Response::error($e->getMessage());
        }
    }

    /**
     * Replaces the `handle()` method for implementors of the `RequiresStagLogin`
     * trait, handling 401 and unauthorized-based errors automatically.
     */
    abstract protected function handleForStagUser(Request $request, StagClient $stag): ResponseFactory|Response;
}

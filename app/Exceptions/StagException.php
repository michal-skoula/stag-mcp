<?php

namespace App\Exceptions;

use Exception;
use Throwable;

/**
 * A STAG call that could not be completed. The message is written for the MCP
 * client to read, so it states what the user has to do rather than what broke.
 */
class StagException extends Exception
{
    /** Endpoint path the failed call went to, set by the client. Never the query string. */
    public ?string $endpoint = null;

    /** HTTP status STAG answered with, or null when it never answered. */
    public ?int $status = null;

    /**
     * Records which call failed, so a reporter can say more than the message does.
     */
    public function at(string $endpoint, ?int $status): static
    {
        $this->endpoint = $endpoint;
        $this->status = $status;

        return $this;
    }

    public static function notAuthorized(): self
    {
        return new self(
            'This account has not authorized IS-STAG yet. Visit '.route('dashboard').' and click "Authorize".'
        );
    }

    public static function ticketRejected(): self
    {
        return new self(
            'The IS-STAG authorization has expired or been revoked. Re-authorize at '.route('dashboard').'. '
            .'Tickets last 30 minutes unless "Keep token valid for longer" was checked.'
        );
    }

    public static function roleRejected(): self
    {
        return new self(
            'IS-STAG refused the requested role for this account.'
        );
    }

    public static function requestFailed(int $status, ?Throwable $previous = null): self
    {
        return new self("IS-STAG returned an unexpected HTTP {$status}.", previous: $previous);
    }

    public static function emptyResponse(string $what): self
    {
        return new self("IS-STAG returned an empty {$what}, which only happens when its web services are misbehaving. Try again later.");
    }

    public static function unreachable(?Throwable $previous = null): self
    {
        return new self('Could not reach the IS-STAG web services.', previous: $previous);
    }
}

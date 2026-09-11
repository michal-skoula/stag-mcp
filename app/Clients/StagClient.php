<?php

namespace App\Clients;

use App\Exceptions\StagException;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Talks to the IS-STAG REST services on behalf of one user.
 *
 * Authentication is the user's STAG ticket, sent as the WSCOOKIE cookie. STAG
 * accepts the same ticket via HTTP Basic (ticket as username, empty password),
 * but the cookie needs no encoding and the server keeps no session, so there is
 * nothing to carry between requests.
 *
 * @see https://is-stag.zcu.cz/napoveda/web-services/ws_prihlasovani.html
 */
final class StagClient
{
    /** @var string Base URL for all STAG endpoints. MUST END WITH A TRAILING SLASH! */
    private const string BASE_URL = 'https://stag-ws.zcu.cz/ws/services/rest2/';

    public function __construct(private readonly User $user) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array<array-key, mixed>
     *
     * @throws StagException
     */
    public function get(string $path, array $params = []): array
    {
        return $this->send(fn (PendingRequest $request): mixed => $request->get($path, $this->withDefaults($params)));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<array-key, mixed>
     *
     * @throws StagException
     */
    public function post(string $path, array $params = []): array
    {
        return $this->send(fn (PendingRequest $request): mixed => $request->post($path, $this->withDefaults($params)));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<array-key, mixed>
     *
     * @throws StagException
     */
    public function put(string $path, array $params = []): array
    {
        return $this->send(fn (PendingRequest $request): mixed => $request->put($path, $this->withDefaults($params)));
    }

    /**
     * Runs the call and translates every failure into a StagException, so callers
     * catch one type and never surface STAG's Czech error bodies.
     *
     * @param  callable(PendingRequest): Response  $call
     * @return array<array-key, mixed>
     *
     * @throws StagException
     */
    private function send(callable $call): array
    {
        if (blank($this->user->stag_token)) {
            throw StagException::notAuthorized();
        }

        try {
            return $call($this->request())->throw()->json();
        } catch (ConnectionException $e) {
            throw StagException::unreachable($e);
        } catch (RequestException $e) {
            throw match ($e->response->status()) {
                401 => StagException::ticketRejected(),
                403 => StagException::roleRejected(),
                default => StagException::requestFailed($e->response->status(), $e),
            };
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function withDefaults(array $params): array
    {
        // todo: future plans, unifies stuff like setting language and user role where that is needed
        return $params;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withHeaders(['Cookie' => "WSCOOKIE={$this->user->stag_token}"])
            ->acceptJson();
    }
}

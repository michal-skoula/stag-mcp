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
 * Most services answer anonymous callers; the ones that do not are reached from a
 * tool using the RequiresStagLogin trait, which checks for a token up front.
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
     * A 401 means different things depending on whether a token was sent at all,
     * and the two need different instructions back to the user.
     *
     * @param  callable(PendingRequest): Response  $call
     * @return array<array-key, mixed>
     *
     * @throws StagException
     */
    private function send(callable $call): array
    {
        try {
            return $call($this->request())->throw()->json();
        } catch (ConnectionException $e) {
            throw StagException::unreachable($e);
        } catch (RequestException $e) {
            throw match ($e->response->status()) {
                401 => $this->hasStagToken()
                    ? StagException::ticketRejected()
                    : StagException::notAuthorized(),
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

    /**
     * Most rest2 services answer anonymous callers, so the token is attached only
     * when there is one. An empty WSCOOKIE would be rejected outright.
     */
    private function request(): PendingRequest
    {
        $request = Http::baseUrl(self::BASE_URL)->acceptJson();

        return $this->hasStagToken()
            ? $request->withHeaders(['Cookie' => "WSCOOKIE={$this->user->stag_token}"])
            : $request;
    }

    private function hasStagToken(): bool
    {
        return filled($this->user->stag_token);
    }
}

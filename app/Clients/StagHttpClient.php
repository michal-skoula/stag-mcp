<?php

namespace App\Clients;

use App\Contracts\StagClient;
use App\Exceptions\StagException;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
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
readonly final class StagHttpClient implements StagClient
{
    /** @var string Base URL for all STAG endpoints. */
    private const string BASE_URL = 'https://stag-ws.zcu.cz/ws/services/rest2';

    /**
     * @param User $user MCP Client user
     * @param bool $showRealExceptions Make endpoints return real STAG errors instead of abstracted error messages
     */
    public function __construct(private User $user, private bool $showRealExceptions = false) {}

    /**
     * @param  array<string, mixed>  $query  Request's query string (&key=val)
     * @return array<array-key, mixed>
     *
     * @throws StagException
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send(fn (PendingRequest $r) => $r->get($path, $this->withDefaults($query)));
    }

    /**
     * @param  array<string, mixed>  $data  Request's JSON body
     * @return array<array-key, mixed>
     *
     * @throws StagException
     */
    public function post(string $path, array $data = []): array
    {
        return $this->send(fn (PendingRequest $r) => $r->post($path, $this->withDefaults($data)));
    }

    /**
     * @param  array<string, mixed>  $query  Request's query string (&key=val)
     * @return array<array-key, mixed>
     *
     * @throws StagException
     */
    public function put(string $path, array $query = []): array
    {
        return $this->send(fn (PendingRequest $r) => $r
            ->withOptions(['query' => $this->toQueryString($this->withDefaults($query))])
            ->put($path));
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
            return $call($this->request())->throw()->json() ?? [];
        } catch (ConnectionException $e) {
            throw StagException::unreachable($e);
        } catch (RequestException $e) {
            if($this->showRealExceptions) {
                throw new StagException('['.$e->response->status().']: '.$e->getMessage(), previous: $e);
            } else {
                throw match ($e->response->status()) {
                    401 => $this->hasStagToken()
                        ? StagException::ticketRejected()
                        : StagException::notAuthorized(),
                    403 => StagException::roleRejected(),
                    default => StagException::requestFailed($e->response->status(), $e),
                };
            }
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
     * STAG reads list parameters as repeated keys (notiIdno=1&notiIdno=2). PHP's
     * http_build_query would write notiIdno[0]=1, which the Java side ignores.
     *
     * @param  array<string, mixed>  $query
     */
    private function toQueryString(array $query): string
    {
        $pairs = [];

        foreach ($query as $key => $value) {
            foreach (Arr::wrap($value) as $item) {
                $pairs[] = rawurlencode((string) $key).'='.rawurlencode((string) $item);
            }
        }

        return implode('&', $pairs);
    }

    /**
     * Most rest2 services answer anonymous callers, so the token is attached only
     * when there is one. An empty WSCOOKIE would be rejected outright.
     */
    private function request(): PendingRequest
    {
        // Normalizing to always include a trailing slash
        $baseUrl = rtrim(self::BASE_URL, '/') . '/';

        $r = Http::baseUrl($baseUrl)->acceptJson();

        return $this->hasStagToken()
            ? $r->withHeaders(['Cookie' => "WSCOOKIE={$this->user->stag_token}"])
            : $r;
    }

    private function hasStagToken(): bool
    {
        return filled($this->user->stag_token);
    }
}

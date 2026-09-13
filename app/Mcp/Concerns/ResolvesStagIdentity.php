<?php

namespace App\Mcp\Concerns;

use App\Clients\StagClient;
use App\Exceptions\StagException;

/**
 * Resolves who the caller is via `help/getStagUserListForActualUserV2`.
 *
 * The V2 endpoint is a strict superset of the non-V2 one: the same
 * `stagUserInfo` array of role rows, plus the person's name, titles and email
 * at the top level. The singular `getStagUserForActualUser` is not an option,
 * as it 403s on an account holding more than one role rather than picking one.
 *
 * One STAG account can hold several roles (student, teacher, ...), each its own
 * row with its own `osCislo`, so `resolveOsCislo()` picks the first row that
 * actually has one rather than assuming a single result.
 */
trait ResolvesStagIdentity
{
    /** @var array<string, mixed>|null */
    private ?array $resolvedIdentity = null;

    /**
     * The caller's full identity payload, fetched once per tool call.
     *
     * @return array<string, mixed>
     *
     * @throws StagException
     */
    protected function resolveIdentity(StagClient $stag): array
    {
        return $this->resolvedIdentity ??= $stag->get('help/getStagUserListForActualUserV2');
    }

    /**
     * @throws StagException
     */
    protected function resolveOsCislo(StagClient $stag): ?string
    {
        foreach ($this->resolveIdentity($stag)['stagUserInfo'] ?? [] as $identity) {
            if (! empty($identity['osCislo'])) {
                return $identity['osCislo'];
            }
        }

        return null;
    }
}

<?php

namespace App\Mcp\Concerns;

use App\Clients\StagClient;
use App\Exceptions\StagException;

/**
 * Resolves the caller's osCislo (personal number) via `help/getStagUserListForActualUser`,
 * for tools that need it but weren't handed it directly. One STAG account can hold
 * several roles (student, teacher, ...), each its own row with its own osCislo, so
 * this picks the first row that actually has one rather than assuming a single result.
 */
trait ResolvesStagIdentity
{
    private ?string $resolvedOsCislo = null;

    private bool $osCisloResolved = false;

    /**
     * @throws StagException
     */
    protected function resolveOsCislo(StagClient $stag): ?string
    {
        if ($this->osCisloResolved) {
            return $this->resolvedOsCislo;
        }

        $this->osCisloResolved = true;

        $users = $stag->get('help/getStagUserListForActualUser');

        foreach ($users['stagUserInfo'] ?? [] as $identity) {
            if (! empty($identity['osCislo'])) {
                $this->resolvedOsCislo = $identity['osCislo'];
                break;
            }
        }

        return $this->resolvedOsCislo;
    }
}

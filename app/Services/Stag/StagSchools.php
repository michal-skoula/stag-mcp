<?php

namespace App\Services\Stag;

use App\Clients\StagHttpClient;
use App\Exceptions\StagException;
use Collator;

/**
 * The schools running STAG, as an onboarding picker wants them: only the
 * ones worth offering, in a sensible order, with the fields a screen needs.
 *
 * `zakaznici/getSeznamProvozovanychWS` lists every deployment, and "listed"
 * is not "usable". Two flags decide what to offer, both `A`/`N`:
 *
 * - `provozniServer`: `N` is a demo or test server (the university's own
 *   `demo` row), not anyone's real data.
 * - `pristupnyBezOmezeni`: `N` means access is restricted. The one such school
 *   timed out for a plain GET from outside its network, so it cannot be
 *   served from here.
 *
 * A row where either flag is anything but `A`, or with no URL, is hidden. The
 * flags are read here rather than filtered when caching, so a change of rule
 * needs no cache flush.
 *
 * Order is the supported school first, then the rest by Czech collation of
 * the short name. The list carries no size or popularity to rank on.
 *
 * Only the school behind {@see StagHttpClient::BASE_URL} is `supported`.
 * Every other row is a real, reachable STAG that this server does not talk
 * to yet, since the client has one hard-coded base URL.
 */
final readonly class StagSchools
{
    public function __construct(private ReferenceData $data) {}

    /**
     * @return CachedStagData<list<array{short_name: string, name: string, url: string, supported: bool}>>
     *
     * @throws StagException
     */
    public function list(): CachedStagData
    {
        $cached = $this->data->schools();

        $schools = array_values(array_map(
            $this->toSchool(...),
            array_filter($cached->data, $this->isOffered(...)),
        ));

        $collator = new Collator('cs_CZ');

        usort($schools, fn (array $a, array $b) => ($b['supported'] <=> $a['supported'])
            ?: $collator->compare($a['short_name'], $b['short_name']));

        return $cached->withData($schools);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isOffered(array $row): bool
    {
        return ($row['provozniServer'] ?? null) === 'A'
            && ($row['pristupnyBezOmezeni'] ?? null) === 'A'
            && filled($row['url'] ?? null)
            && filled($row['nazevSkoly'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{short_name: string, name: string, url: string, supported: bool}
     */
    private function toSchool(array $row): array
    {
        $url = rtrim($row['url'], '/');

        return [
            'short_name' => $row['nazevSkoly'],
            'name' => $row['celyNazevSkoly'] ?? $row['nazevSkoly'],
            'url' => $url,
            'supported' => $url.'/services/rest2' === rtrim(StagHttpClient::BASE_URL, '/'),
        ];
    }
}

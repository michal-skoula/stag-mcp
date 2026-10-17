<?php

namespace App\Services\Teachers;

use App\Contracts\StagClient;
use App\Exceptions\StagException;
use Illuminate\Support\Facades\Cache;

/**
 * The university's teacher directory, from `ucitel/getUciteleKatedry`.
 *
 * `katedra` is hierarchical on STAG's side: a faculty code returns the whole
 * faculty, and REK (the rectorate, the root workplace) returns everyone, so
 * one call covers the university and a department narrows it without us
 * needing the workplace tree. `jenAktualni=false` is always sent, because by
 * default STAG drops every `platnost=N` row, and that half of the directory
 * includes people teaching right now (student demonstrators, doctoral
 * assistants). Only the literal string "true" parses as true there, so it has
 * to be "false" rather than any falsy-looking value we happen to pick.
 *
 * Rows are trimmed to what search needs, with the search key built once here
 * rather than on every query: Str::ascii over all 21k names costs ~40 ms,
 * where a per-row transliterator at query time took over a second.
 *
 * @phpstan-type TeacherRow array{
 *     id: int,
 *     first_name: ?string,
 *     last_name: ?string,
 *     titles_before: ?string,
 *     titles_after: ?string,
 *     department: ?string,
 *     other_department: ?string,
 *     email: ?string,
 *     active: bool,
 *     employee: bool,
 *     key: string,
 * }
 */
final readonly class TeacherDirectory
{
    /** Workplace code STAG treats as the root of the hierarchy. */
    public const string WHOLE_UNIVERSITY = 'REK';

    public const string TEACHER_CACHE_PREFIX = 'stag_teachers_';

    /**
     * How often the cache expires, in seconds.
     *
     * todo: currently a long TTL, but I assume teachers / lecturers change once per academic year, around the start.
     *       this current approach might be a bit wasteful and take time to update if the cache is invalidated right
     *       before the year starts. Worth looking into a better way of caching this, ex. on a date. Employees likely
     *       change all the time, but we care about teachers.
     */
    public const int TEACHER_CACHE_TTL_SECONDS = 2_592_000;

    public function __construct(private StagClient $stag) {}

    /**
     * @return list<TeacherRow>
     *
     * @throws StagException
     */
    public function search(?string $name, ?string $department = null, bool $activeOnly = false, bool $employeesOnly = false): array
    {
        return TeacherSearch::search($this->snapshot($department), $name, $activeOnly, $employeesOnly);
    }

    /**
     * Every teacher in one scope: a department or faculty code, or the whole
     * university when null.
     *
     * @return list<TeacherRow>
     *
     * @throws StagException
     */
    public function snapshot(?string $department = null): array
    {
        // todo: (#1) Currently, if STAG has an outage or some other problem like breaking the endpoint while our cache
        //       has expired, the user gets an exception since it can't retrieve it. A better solution would be to
        //       return the stale cache, since it will be exactly the same or mostly accurate, and also probably add
        //       a new array key `using_outdated_data: bool` or similar. Requires custom cache invalidation with
        //       rememberForever and keeping track manually. Store a `retrieved_at: timestamp` key or similar.

        // todo: (#2) Sync job to retrieve the expensive STAG query ahead of time to not slow down unlucky users who first
        //       discover the cache is outdated. this would get scheduled either via the `Schedule::class` facade
        //       or queued within this class. The scheduler is more obvious so I'd lean in that direction.

        /*
         * Known desync, accepted for now: every scope is its own key with its own
         * expiry, so REK and a department can hold snapshots from different days.
         * Around the September `platnost` flip that means one person can show as
         * inactive in a name search and active in a department search, until the
         * older key expires. The sync job (#2) resolves it by refreshing every key
         * in the same run.
         */

        $scope = $department ?? self::WHOLE_UNIVERSITY;

        // Only regenerate the cache for the requested department.
        if ($scope !== self::WHOLE_UNIVERSITY) {
            return Cache::remember(
                $this->toCacheKey($scope),
                self::TEACHER_CACHE_TTL_SECONDS,
                fn () => $this->fetch($scope)
            );
        }

        // We return the result as-is, agent will sort through it. both scoped and WHOLE_UNIVERSITY queries return the same shape.
        // The whole university is never empty, so an empty answer is a STAG fault. Throwing keeps it out of the cache.
        return Cache::remember(
            $this->toCacheKey('all'),
            self::TEACHER_CACHE_TTL_SECONDS,
            fn () => $this->fetch(self::WHOLE_UNIVERSITY) ?: throw StagException::emptyResponse('teacher directory'));
    }

    private function toCacheKey(string $department): string
    {
        return self::TEACHER_CACHE_PREFIX.trim(mb_strtolower($department));
    }

    /**
     * One uncached call to STAG, trimmed to {@see TeacherRow}.
     *
     * @return list<TeacherRow>
     *
     * @throws StagException
     */
    private function fetch(string $scope): array
    {
        // `ucitel` is a wrapper key containing the teachers array.
        $rows = $this->stag->get('ucitel/getUciteleKatedry', [
            'katedra' => $scope,
            'jenAktualni' => 'false',
        ])['ucitel'] ?? [];

        return array_map($this->toRow(...), $rows);
    }

    /**
     * telefon/telefon2 (free text), url, zkrBudovy/cisloMistnosti (sparse)
     * and phdSkolitel (N on every row sampled) are dropped. They belong to a
     * detail lookup, not to search.
     *
     * @param  array<string, mixed>  $row
     * @return TeacherRow
     */
    private function toRow(array $row): array
    {
        return [
            'id' => (int) $row['ucitIdno'],
            'first_name' => $this->nullIfBlank($row['jmeno'] ?? null),
            'last_name' => $this->nullIfBlank($row['prijmeni'] ?? null),
            'titles_before' => $this->nullIfBlank($row['titulPred'] ?? null),
            'titles_after' => $this->nullIfBlank($row['titulZa'] ?? null),
            'department' => $this->nullIfBlank($row['katedra'] ?? null),
            'other_department' => $this->nullIfBlank($row['pracovisteDalsi'] ?? null),
            'email' => $this->nullIfBlank($row['email'] ?? null),
            'active' => $this->fromStagBool($row['platnost'] ?? null) ?? false,
            'employee' => $this->fromStagBool($row['zamestnanec'] ?? null) ?? false,
            'key' => TeacherSearch::normalize(($row['prijmeni'] ?? '').' '.($row['jmeno'] ?? '')),
        ];
    }

    private function nullIfBlank(?string $value): ?string
    {
        $trimmed = $value === null ? '' : trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function fromStagBool(?string $value): ?bool
    {
        return match ($value !== null ? mb_strtoupper($value) : null) {
            'A', 'ANO' => true,
            'N', 'NE' => false,
            default => null,
        };
    }
}

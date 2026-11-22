<?php

namespace App\Services\Teachers;

use App\Contracts\StagClient;
use App\Exceptions\StagException;
use App\Services\Stag\CachedStagData;
use App\Services\Stag\ReferenceDataCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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
     * Index of the scopes worth refreshing in the background, as
     * `[cache key => retrieved_at timestamp]`. A cache store cannot list keys,
     * and the timestamps spare the hourly due-check from loading a 6 MB entry.
     */
    public const string SCOPE_INDEX_KEY = 'stag_teachers_scopes';

    /**
     * How long an entry counts as fresh, in seconds. Past this, the next lookup
     * asks STAG again and falls back to the old snapshot if that fails.
     *
     * Teachers mostly change once a year, at the start of the academic year,
     * and the scheduled refresh keeps entries well inside this. It matters only
     * when the job cannot run, e.g. no account holds a live STAG ticket.
     */
    public const int TEACHER_CACHE_TTL_SECONDS = 2_592_000;

    /** The scheduled refresh replaces a snapshot once it is this old. */
    public const int REFRESH_AFTER_SECONDS = 604_800;

    /**
     * Around the September `platnost` flip people change status week by week,
     * so the refresh runs daily from mid-September to mid-October.
     */
    public const int SEASON_REFRESH_AFTER_SECONDS = 86_400;

    public function __construct(
        private StagClient $stag,
        private ReferenceDataCache $cache = new ReferenceDataCache,
    ) {}

    /**
     * @return CachedStagData<list<TeacherRow>>
     *
     * @throws StagException
     */
    public function search(?string $name, ?string $department = null, bool $activeOnly = false, bool $employeesOnly = false): CachedStagData
    {
        $snapshot = $this->snapshot($department);

        return $snapshot->withData(TeacherSearch::search($snapshot->data, $name, $activeOnly, $employeesOnly));
    }

    /**
     * Every teacher in one scope: a department or faculty code, or the whole
     * university when null.
     *
     * Past its TTL a scope is fetched again. If STAG fails then, the old
     * snapshot comes back flagged `outdated` rather than an exception, see
     * {@see ReferenceDataCache}. Each scope is its own entry holding STAG's own
     * answer for that code: REK rows only name their own workplace, not the
     * tree above it, so a key split out of REK would be incomplete for any code
     * with children (FAV: 179 of 1,878).
     *
     * Entries expire independently, so REK and a department can briefly hold
     * snapshots from different days. The scheduled {@see self::refreshAll()}
     * replaces them together, which is what keeps them in step around the
     * September `platnost` flip.
     *
     * @return CachedStagData<list<TeacherRow>>
     *
     * @throws StagException
     */
    public function snapshot(?string $department = null): CachedStagData
    {
        $scope = $department ?? self::WHOLE_UNIVERSITY;
        $key = $this->toCacheKey($scope);

        $snapshot = $this->cache->remember($key, self::TEACHER_CACHE_TTL_SECONDS, fn () => $this->fetch($scope));

        $this->indexScope($key, $snapshot);

        return $snapshot;
    }

    /**
     * Whether the scheduled refresh has work to do: nothing cached yet, or a
     * snapshot older than the refresh age. The oldest scope decides, so one
     * lagging department pulls the whole set back into step.
     */
    public function isRefreshDue(): bool
    {
        $index = $this->scopeIndex();

        if (! isset($index[$this->toCacheKey(self::WHOLE_UNIVERSITY)])) {
            return true;
        }

        $age = CarbonImmutable::now()->getTimestamp() - min($index);

        return $age >= $this->refreshAfterSeconds();
    }

    /**
     * Replaces REK and every indexed department scope in one run, so they end
     * up from the same moment. A scope whose call fails keeps its old snapshot
     * (nothing is written on failure). If REK itself fails, STAG is most likely
     * down, so the rest are not attempted.
     *
     * Scopes that came back empty are never indexed, so a typo like "XYZ"
     * typed into a search cannot turn into a standing STAG call.
     *
     * @return array<string, bool> scope => whether it was refreshed
     */
    public function refreshAll(): array
    {
        /* Cache keys hold lowercased scope codes, and STAG accepts either case. */
        $scopes = [self::WHOLE_UNIVERSITY];

        foreach (array_keys($this->scopeIndex()) as $key) {
            $scope = mb_strtoupper(substr($key, strlen(self::TEACHER_CACHE_PREFIX)));

            if ($key !== $this->toCacheKey(self::WHOLE_UNIVERSITY)) {
                $scopes[] = $scope;
            }
        }

        $results = [];

        foreach ($scopes as $scope) {
            $key = $this->toCacheKey($scope);

            try {
                $this->indexScope($key, $this->cache->refresh($key, fn () => $this->fetch($scope)));
                $results[$scope] = true;
            } catch (StagException $e) {
                Log::warning("Teacher directory refresh of [{$scope}] failed, keeping the old snapshot.", ['exception' => $e->getMessage()]);
                $results[$scope] = false;

                if ($scope === self::WHOLE_UNIVERSITY) {
                    break;
                }
            }
        }

        return $results;
    }

    private function refreshAfterSeconds(): int
    {
        $now = CarbonImmutable::now();
        $seasonStart = $now->setDate($now->year, 9, 15)->startOfDay();
        $seasonEnd = $now->setDate($now->year, 10, 15)->endOfDay();

        return $now->between($seasonStart, $seasonEnd)
            ? self::SEASON_REFRESH_AFTER_SECONDS
            : self::REFRESH_AFTER_SECONDS;
    }

    private function toCacheKey(string $scope): string
    {
        return self::TEACHER_CACHE_PREFIX.trim(mb_strtolower($scope === self::WHOLE_UNIVERSITY ? 'all' : $scope));
    }

    /**
     * @return array<string, int> cache key => retrieved_at timestamp
     */
    private function scopeIndex(): array
    {
        $index = Cache::get(self::SCOPE_INDEX_KEY);

        return is_array($index) ? $index : [];
    }

    /**
     * @param  CachedStagData<list<TeacherRow>>  $snapshot
     */
    private function indexScope(string $key, CachedStagData $snapshot): void
    {
        $timestamp = $snapshot->retrievedAt->getTimestamp();

        if ($snapshot->data === [] || ($this->scopeIndex()[$key] ?? null) === $timestamp) {
            return;
        }

        Cache::lock(self::SCOPE_INDEX_KEY.':lock', 5)->block(5, function () use ($key, $timestamp): void {
            Cache::forever(self::SCOPE_INDEX_KEY, [...$this->scopeIndex(), $key => $timestamp]);
        });
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

        /*
         * The whole university is never empty, so an empty answer is a STAG
         * fault and must stay out of the cache. An empty department is cached:
         * an unknown code legitimately returns nothing.
         */
        if ($rows === [] && $scope === self::WHOLE_UNIVERSITY) {
            throw StagException::emptyResponse('teacher directory');
        }

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

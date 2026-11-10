<?php

namespace App\Services\StudyPlans;

use App\Clients\StagHttpClient;
use App\Exceptions\StagException;
use Illuminate\Support\Facades\Cache;

/**
 * Fetches study plans, a student's progress and the certificate catalogue.
 *
 * Plans and the catalogue answer anonymously and are the same for everyone,
 * so they are cached across users. A published plan is fixed for its year;
 * the week-long TTL only bounds how long a mid-year correction takes to show.
 * Failed calls are never cached: exceptions leave the cache closure, and an
 * unknown plan (STAG answers 204) is returned as null outside it.
 *
 * @phpstan-type Certificate array{
 *     certificate_id: int,
 *     name: ?string,
 *     faculty: ?string,
 *     programme_name: ?string,
 *     level: ?string,
 * }
 */
final readonly class StudyPlanRepository
{
    public const string PLAN_CACHE_PREFIX = 'stag_study_plan_';

    public const string CERTIFICATES_CACHE_KEY = 'stag_certificates';

    public const int CACHE_TTL_SECONDS = 604_800;

    public function __construct(private StagHttpClient $stag) {}

    /**
     * @throws StagException
     */
    public function plan(int $planId): ?StudyPlan
    {
        $key = self::PLAN_CACHE_PREFIX.$planId;
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return StudyPlan::fromArray($cached);
        }

        $payload = $this->stag->get('vsp/getStudijniPlan', ['stplIdno' => $planId]);

        if ($payload === [] || ($payload['emptyPlan'] ?? false) === true) {
            return null;
        }

        $plan = StudyPlan::fromStag($payload, $this->planInfo($planId));
        Cache::put($key, $plan->toArray(), self::CACHE_TTL_SECONDS);

        return $plan;
    }

    /**
     * The ticket holder's record. `osCislo` is mandatory upstream but ignored,
     * so it is passed only to satisfy STAG. Never cached: it is one person's
     * data and changes with every grade.
     *
     * @throws StagException
     */
    public function studentRecord(string $osCislo): StudentRecord
    {
        return StudentRecord::fromXml($this->stag->getXml('vsp/getStudent', ['osCislo' => $osCislo]));
    }

    /**
     * The newest plan of one obor, which for a certificate is the plan to
     * measure against.
     *
     * @throws StagException
     */
    public function currentPlanForObor(int $oborIdno): ?int
    {
        $plans = $this->stag->get('programy/getPlanyOboru', ['oborIdno' => $oborIdno])['planInfo'] ?? [];

        usort($plans, fn (array $a, array $b) => [(string) ($b['rokPlatnosti'] ?? ''), (string) ($b['verze'] ?? '')]
            <=> [(string) ($a['rokPlatnosti'] ?? ''), (string) ($a['verze'] ?? '')]);

        return isset($plans[0]['stplIdno']) ? (int) $plans[0]['stplIdno'] : null;
    }

    /**
     * Certificate programmes are ordinary programmes coded `9999` (or
     * `9999-XXX`) with no degree title. REK's `9999` is "Mimořádné studium",
     * 160 single-course enrolments rather than certificates, so it is left out.
     * Each certificate is one obor of such a programme.
     *
     * @return list<Certificate>
     *
     * @throws StagException
     */
    public function certificates(): array
    {
        return Cache::remember(self::CERTIFICATES_CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
            $programmes = $this->stag->get('programy/getStudijniProgramy', ['pouzePlatne' => 'true'])['programInfo'] ?? [];
            $certificates = [];

            foreach ($programmes as $programme) {
                if (! str_starts_with((string) ($programme['kod'] ?? ''), '9999') || ($programme['fakulta'] ?? null) === 'REK') {
                    continue;
                }

                $obory = $this->stag->get('programy/getOboryStudijnihoProgramu', ['stprIdno' => $programme['stprIdno']])['oborInfo'] ?? [];

                foreach ($obory as $obor) {
                    $certificates[] = [
                        'certificate_id' => (int) $obor['oborIdno'],
                        'name' => $this->nullIfBlank($obor['nazev'] ?? null),
                        'faculty' => $this->nullIfBlank($obor['fakulta'] ?? $programme['fakulta'] ?? null),
                        'programme_name' => $this->nullIfBlank($programme['nazev'] ?? null),
                        'level' => $this->nullIfBlank($programme['typ'] ?? null),
                    ];
                }
            }

            /* Every valid programme list holds certificates, so none means STAG misbehaved. */
            return $certificates ?: throw StagException::emptyResponse('certificate programme list');
        });
    }

    /**
     * Only for the plan name and semester count, so a refusal degrades to
     * those being null rather than failing the plan.
     *
     * @return array<string, mixed>
     */
    private function planInfo(int $planId): array
    {
        try {
            $info = $this->stag->get('programy/getPlanInfo', ['stplIdno' => $planId]);
        } catch (StagException) {
            return [];
        }

        return array_is_list($info) ? ($info[0] ?? []) : $info;
    }

    private function nullIfBlank(?string $value): ?string
    {
        $trimmed = $value === null ? '' : trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}

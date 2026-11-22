<?php

namespace App\Services\Stag;

use App\Contracts\StagClient;
use App\Exceptions\StagException;
use App\Mcp\Concerns\ResolvesStagIdentity;
use App\Mcp\Enums\StudyForm;
use App\Mcp\Enums\StudyProgrammeType;
use App\Mcp\Enums\StudyState;
use App\Services\Observability\StagErrorReporter;

/**
 * Who the ticket holder is in STAG: the person, plus one entry per role
 * enriched with `student/getStudentInfo` or `ucitel/getUcitelInfo`.
 *
 * This is what the `get-profile` tool returns and what onboarding prefill reads
 * its suggestions from, so both go through here and a STAG call is never
 * written twice. The array shape is the tool's output schema.
 */
final class StagProfile
{
    use ResolvesStagIdentity;

    public function __construct(private readonly StagClient $stag) {}

    /**
     * @return array{name: array<string, mixed>, email: string|null, roles: list<array<string, mixed>>}
     *
     * @throws StagException
     */
    public function describe(): array
    {
        $identity = $this->resolveIdentity($this->stag);

        return [
            'name' => [
                'first' => $identity['jmeno'] ?? null,
                'last' => $identity['prijmeni'] ?? null,
                'titles_before' => $identity['titulPred'] ?? null,
                'titles_after' => $identity['titulZa'] ?? null,
                'full' => $this->fullName($identity),
            ],
            'email' => $identity['email'] ?? null,
            'roles' => array_map(
                fn (array $row) => $this->toRole($row),
                $identity['stagUserInfo'] ?? [],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    private function fullName(array $identity): string
    {
        $parts = [
            $identity['titulPred'] ?? null,
            $identity['jmeno'] ?? null,
            $identity['prijmeni'] ?? null,
        ];

        $name = implode(' ', array_filter($parts, fn ($part) => filled($part)));

        return filled($identity['titulZa'] ?? null)
            ? $name.', '.$identity['titulZa']
            : $name;
    }

    /**
     * One role row, with the per-role detail STAG keeps in another namespace.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function toRole(array $row): array
    {
        return [
            'role' => $row['role'] ?? null,
            'role_name' => $row['roleNazev'] ?? null,
            'user_name' => $row['userName'] ?? null,
            'faculty' => $row['fakulta'] ?? null,
            'department' => $row['katedra'] ?? null,
            'student_id' => $row['osCislo'] ?? null,
            'teacher_id' => $row['ucitIdno'] ?? null,
            'email' => $row['email'] ?? null,
            'student' => $this->studentDetail($row),
            'teacher' => $this->teacherDetail($row),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function studentDetail(array $row): ?array
    {
        if (empty($row['osCislo'])) {
            return null;
        }

        $info = $this->fetchDetail('student/getStudentInfo', ['osCislo' => $row['osCislo']]);

        if ($info === null) {
            return null;
        }

        return [
            'student_id' => $info['osCislo'],
            'orion_login' => $info['userName'] ?? null,
            'email' => $info['email'] ?? null,
            'state' => $this->labelFor($info['stav'] ?? null, StudyState::class),
            'form' => $this->labelFor($info['formaSp'] ?? null, StudyForm::class),
            'type' => $this->labelFor($info['typSp'] ?? null, StudyProgrammeType::class),
            'year_of_study' => $info['rocnik'] ?? null,
            'programme' => [
                'name' => $info['nazevSp'] ?? null,
                'code' => $info['kodSp'] ?? null,
                'faculty' => $info['fakultaSp'] ?? null,
                'id' => $info['stprIdno'] ?? null,
                'specialisation' => $info['oborKomb'] ?? null,
                'specialisation_ids' => $info['oborIdnos'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function teacherDetail(array $row): ?array
    {
        if (empty($row['ucitIdno'])) {
            return null;
        }

        $info = $this->fetchDetail('ucitel/getUcitelInfo', ['ucitIdno' => $row['ucitIdno']]);

        if ($info === null) {
            return null;
        }

        return [
            'teacher_id' => $info['ucitIdno'],
            'department' => $info['katedra'] ?? null,
            'other_department' => $info['pracovisteDalsi'] ?? null,
            'email' => $info['email'] ?? null,
            'phones' => array_values(array_unique(array_filter(
                [$info['telefon'] ?? null, $info['telefon2'] ?? null],
                fn (?string $phone) => filled($phone),
            ))),
            'website' => $info['url'] ?? null,
            'office' => [
                'building' => $info['zkrBudovy'] ?? null,
                'room' => $info['cisloMistnosti'] ?? null,
            ],
            'active' => $this->fromStagBool($info['platnost'] ?? null),
            'employee' => $this->fromStagBool($info['zamestnanec'] ?? null),
            'phd_supervisor' => $this->fromStagBool($info['phdSkolitel'] ?? null),
        ];
    }

    /**
     * Fetches one role's detail, degrading to null rather than failing.
     *
     * An account can hold a role whose detail endpoint refuses it, and an
     * unknown id comes back as an empty array rather than a 404, so both the
     * 403 and the empty shape have to mean "no detail for this row" while the
     * other roles still answer.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    private function fetchDetail(string $path, array $query): ?array
    {
        try {
            $info = $this->stag->get($path, $query);
        } catch (StagException $e) {
            app(StagErrorReporter::class)->degraded($e, 'get-profile role detail');

            return null;
        }

        return $info === [] ? null : $info;
    }

    /**
     * The English wording for one of STAG's codes.
     *
     * Falls back to the raw code when STAG sends one these enums have not seen.
     * None of the three domains behind them is guaranteed stable, and an
     * unrecognised code still tells the reader more than a null does.
     *
     * @param  class-string<StudyForm|StudyProgrammeType|StudyState>  $enum
     */
    private function labelFor(?string $code, string $enum): ?string
    {
        return $enum::tryFrom((string) $code)?->label() ?? $code;
    }

    /**
     * STAG writes booleans as A/N here, and as ANO/NE elsewhere in the same API.
     */
    private function fromStagBool(?string $value): ?bool
    {
        return match ($value) {
            'A', 'ANO' => true,
            'N', 'NE' => false,
            default => null,
        };
    }
}

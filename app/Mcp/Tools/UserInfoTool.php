<?php

namespace App\Mcp\Tools;

use App\Clients\StagClient;
use App\Exceptions\StagException;
use App\Mcp\Concerns\RequiresStagLogin;
use App\Mcp\Concerns\ResolvesStagIdentity;
use App\Mcp\Enums\StudyForm;
use App\Mcp\Enums\StudyProgrammeType;
use App\Mcp\Enums\StudyState;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('who-am-i')]
#[Title('Who Am I')]
#[Description('Who the caller is in STAG: name, email, and one entry per role the account holds. A student role carries the study programme, year, form and state (use get-study-advisor for the referentka); a teaching role carries the department and contact details. One account can hold several roles at once, each with its own personal number, so this is how to find the os_cislo that get-znamky and get-kalendar accept. Takes no arguments - it always describes the account behind the ticket.')]
#[IsReadOnly]
class UserInfoTool extends Tool
{
    use RequiresStagLogin;
    use ResolvesStagIdentity;

    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fhelp
    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fstudent
    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fucitel

    /**
     * The tool describes the ticket holder, so there is nothing to filter on.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $nullableString = fn () => $schema->anyOf([$schema->string()])->nullable();
        $nullableInt = fn () => $schema->anyOf([$schema->integer()])->nullable();
        $nullableBool = fn () => $schema->anyOf([$schema->boolean()])->nullable();

        return [
            'name' => $schema->object([
                'first' => $nullableString()->description('Given name.'),
                'last' => $nullableString()->description('Surname, as STAG stores it (often all caps).'),
                'title_before' => $nullableString()->description('Titles before the name, e.g. "JUDr.".'),
                'title_after' => $nullableString()->description('Titles after the name, e.g. "Ph.D.".'),
                'full' => $schema->string()->description('Titles and name joined for display.'),
            ])->description('The person behind the account. The same for every role.'),
            'email' => $nullableString()->description('Primary contact address on the account. A role may carry a different one.'),
            'roles' => $schema->array()->description('Every STAG role this account holds, most capable first as STAG orders them.')
                ->items($schema->object([
                    'role' => $nullableString()->description('Role code, e.g. "ST" (student) or "VY" (teacher).'),
                    'role_name' => $nullableString()->description("STAG's Czech name for the role."),
                    'user_name' => $nullableString()->description('Login this role is held under.'),
                    'fakulta' => $nullableString()->description('Faculty abbreviation, e.g. "FAV".'),
                    'katedra' => $nullableString()->description('Department abbreviation, set for teaching roles.'),
                    'os_cislo' => $nullableString()->description('Personal number. Set for student roles; pass it to get-znamky or get-kalendar.'),
                    'ucit_idno' => $nullableInt()->description('Teacher id. Set for teaching roles.'),
                    'email' => $nullableString()->description('Address for this role specifically.'),
                    'student' => $schema->anyOf([$schema->object([
                        'os_cislo' => $schema->string()->description('Personal number this study is recorded under.'),
                        'orion_login' => $nullableString()->description('University-wide (Orion) login.'),
                        'email' => $nullableString()->description('School address for this study.'),
                        'state' => $nullableString()->description('Whether the study is running: "studying", "interrupted" or "not studying".'),
                        'form' => $nullableString()->description('How the study is attended: "full-time", "combined" or "distance".'),
                        'type' => $nullableString()->description('Degree level: "bachelor", "follow-up master", "master" or "doctoral".'),
                        'rocnik' => $nullableString()->description('Year of study, as STAG sends it (a quoted number).'),
                        'programme' => $schema->object([
                            'name' => $nullableString()->description('Programme name. Czech only; STAG ignores lang on this endpoint.'),
                            'code' => $nullableString()->description('Accreditation code, e.g. "B0613A140037".'),
                            'faculty' => $nullableString()->description('Faculty running the programme.'),
                            'id' => $nullableString()->description("STAG's internal programme id, accepted by other STAG endpoints."),
                            'specialisation' => $nullableString()->description('Specialisation combination, e.g. "SWI23bp".'),
                            'specialisation_ids' => $nullableString()->description("STAG's internal specialisation ids."),
                        ])->description('The study programme being read.'),
                    ])])->description('Study detail, present on student roles only.')->nullable(),
                    'teacher' => $schema->anyOf([$schema->object([
                        'ucit_idno' => $schema->integer()->description('Teacher id.'),
                        'katedra' => $nullableString()->description('Home department.'),
                        'pracoviste_dalsi' => $nullableString()->description('Further workplaces.'),
                        'email' => $nullableString()->description('Work email.'),
                        'telefon' => $nullableString()->description('Work phone.'),
                        'telefon2' => $nullableString()->description('Second work phone.'),
                        'url' => $nullableString()->description('Personal page.'),
                        'zkr_budovy' => $nullableString()->description('Office building abbreviation.'),
                        'cislo_mistnosti' => $nullableString()->description('Office room number.'),
                        'platnost' => $nullableBool()->description('Whether the record is current.'),
                        'zamestnanec' => $nullableBool()->description('Whether this person is an employee.'),
                        'phd_skolitel' => $nullableBool()->description('Whether this person supervises doctoral students.'),
                    ])])->description('Teaching detail, present on teaching roles only.')->nullable(),
                ])),
        ];
    }

    /**
     * Handle the tool request.
     *
     * @throws StagException
     */
    protected function handleForStagUser(Request $request, StagClient $stag): ResponseFactory|Response
    {
        $identity = $this->resolveIdentity($stag);

        $roles = array_map(
            fn (array $row) => $this->toRole($row, $stag),
            $identity['stagUserInfo'] ?? [],
        );

        return Response::structured([
            'name' => [
                'first' => $identity['jmeno'] ?? null,
                'last' => $identity['prijmeni'] ?? null,
                'title_before' => $identity['titulPred'] ?? null,
                'title_after' => $identity['titulZa'] ?? null,
                'full' => $this->fullName($identity),
            ],
            'email' => $identity['email'] ?? null,
            'roles' => $roles,
        ]);
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
    private function toRole(array $row, StagClient $stag): array
    {
        return [
            'role' => $row['role'] ?? null,
            'role_name' => $row['roleNazev'] ?? null,
            'user_name' => $row['userName'] ?? null,
            'fakulta' => $row['fakulta'] ?? null,
            'katedra' => $row['katedra'] ?? null,
            'os_cislo' => $row['osCislo'] ?? null,
            'ucit_idno' => $row['ucitIdno'] ?? null,
            'email' => $row['email'] ?? null,
            'student' => $this->studentDetail($row, $stag),
            'teacher' => $this->teacherDetail($row, $stag),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function studentDetail(array $row, StagClient $stag): ?array
    {
        if (empty($row['osCislo'])) {
            return null;
        }

        $info = $this->fetchDetail($stag, 'student/getStudentInfo', ['osCislo' => $row['osCislo']]);

        if ($info === null) {
            return null;
        }

        return [
            'os_cislo' => $info['osCislo'],
            'orion_login' => $info['userName'] ?? null,
            'email' => $info['email'] ?? null,
            'state' => $this->labelFor($info['stav'] ?? null, StudyState::class),
            'form' => $this->labelFor($info['formaSp'] ?? null, StudyForm::class),
            'type' => $this->labelFor($info['typSp'] ?? null, StudyProgrammeType::class),
            'rocnik' => $info['rocnik'] ?? null,
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
    private function teacherDetail(array $row, StagClient $stag): ?array
    {
        if (empty($row['ucitIdno'])) {
            return null;
        }

        $info = $this->fetchDetail($stag, 'ucitel/getUcitelInfo', ['ucitIdno' => $row['ucitIdno']]);

        if ($info === null) {
            return null;
        }

        return [
            'ucit_idno' => $info['ucitIdno'],
            'katedra' => $info['katedra'] ?? null,
            'pracoviste_dalsi' => $info['pracovisteDalsi'] ?? null,
            'email' => $info['email'] ?? null,
            'telefon' => $info['telefon'] ?? null,
            'telefon2' => $info['telefon2'] ?? null,
            'url' => $info['url'] ?? null,
            'zkr_budovy' => $info['zkrBudovy'] ?? null,
            'cislo_mistnosti' => $info['cisloMistnosti'] ?? null,
            'platnost' => $this->fromStagBool($info['platnost'] ?? null),
            'zamestnanec' => $this->fromStagBool($info['zamestnanec'] ?? null),
            'phd_skolitel' => $this->fromStagBool($info['phdSkolitel'] ?? null),
        ];
    }

    /**
     * Fetches one role's detail, degrading to null rather than failing the tool.
     *
     * An account can hold a role whose detail endpoint refuses it, and an
     * unknown id comes back as an empty array rather than a 404, so both the
     * 403 and the empty shape have to mean "no detail for this row" while the
     * other roles still answer.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    private function fetchDetail(StagClient $stag, string $path, array $query): ?array
    {
        try {
            $info = $stag->get($path, $query);
        } catch (StagException) {
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

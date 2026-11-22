<?php

namespace App\Mcp\Tools;

use App\Clients\StagHttpClient;
use App\Exceptions\StagException;
use App\Mcp\Concerns\RequiresStagLogin;
use App\Services\Teachers\BusinessCard;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-ucitel-info')]
#[Title('Get Ucitel Info')]
#[Description('Full detail for one teacher or staff member by teacher_id: name and titles, department, email and phone, which office they sit in, their web page, which study programmes they guarantee, and which subjects they teach this academic year (or another one via year), each with their role (guarantor, lecturer, instructor, seminar leader, examiner). Find the teacher_id with search-ucitele first. Student teachers (demonstrators, doctoral assistants) often have no office or phone recorded, so email is the only contact STAG holds for them. Their subjects list is the best way to see what they teach, since their timetable can miss them.')]
#[IsReadOnly]
class GetUcitelInfoTool extends Tool
{
    use RequiresStagLogin;

    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fucitel
    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fvizitka
    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fpredmety

    /**
     * Output role => [ANO/NE flag, share field] on `predmety/getPredmetyByUcitel`.
     * The examiner flag has no share.
     */
    private const array SUBJECT_ROLES = [
        'guarantor' => ['garant', 'garantPodil'],
        'lecturer' => ['prednasejici', 'prednasejiciPodil'],
        'instructor' => ['cvicici', 'cviciciPodil'],
        'seminar_leader' => ['seminarici', 'seminariciPodil'],
        'examiner' => ['examinator', null],
    ];

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'teacher_id' => $schema->integer()
                ->description('The teacher_id (ucitIdno) from search-ucitele, or from any subject or timetable tool that lists teachers.')
                ->required(),
            'year' => $schema->integer()
                ->description('Academic year to list subjects for, as its starting year: 2025 means 2025/2026. Defaults to the current academic year.'),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $nullableString = fn () => $schema->anyOf([$schema->string()])->nullable();

        return [
            'teacher_id' => $schema->integer()->description('ucitIdno.'),
            'name' => $schema->string()->description('Display name with titles, e.g. "doc. Ing. Pavel Herout, Ph.D."'),
            'first_name' => $nullableString()->description('First name.'),
            'last_name' => $nullableString()->description('Surname.'),
            'titles_before' => $nullableString()->description('Titles before the name, e.g. "doc. Ing."'),
            'titles_after' => $nullableString()->description('Titles after the name, e.g. "Ph.D."'),
            'department' => $nullableString()->description('Home department code, e.g. "KIV". Null for many external teachers.'),
            'other_department' => $nullableString()->description('Further workplace codes, comma-separated, when STAG records any, e.g. "FF,KSA".'),
            'active' => $schema->boolean()->description('STAG\'s platnost flag. False does not mean the person has left: student demonstrators and doctoral assistants are often false while teaching.'),
            'employee' => $schema->boolean()->description('A university employee, as opposed to an external teacher.'),
            'email' => $nullableString()->description('Work email. Usually the fastest way to reach them.'),
            'phones' => $schema->array()->items($schema->string())->description('Phones exactly as STAG stores them, which is a free-text field: a four-digit internal extension, a full number, a mobile, or occasionally something that is not a phone number at all. Do not assume one can be dialled from outside the university without looking at it. Usually empty.'),
            'website' => $nullableString()->description('Personal web page, verbatim. Often lists teaching materials and consultation times.'),
            'offices' => $schema->array()->description('Where they sit. Usually one entry; more when they hold phone lines in different rooms. Empty when no business card is published or the person hid their room.')
                ->items($schema->object([
                    'building' => $nullableString()->description('Building abbreviation, e.g. "UC". Resolve it with list-budovy for an address.'),
                    'room' => $nullableString()->description('Room number within the building.'),
                    'workplace' => $nullableString()->description('Workplace abbreviation the room belongs to, e.g. "KIV".'),
                ])),
            'office_hours_note' => $schema->string()->description('Where to find this person\'s consultation hours.'),
            'card_updated' => $nullableString()->description('When the business card was last edited, ISO date. Null without a card. An old date means the room is unconfirmed rather than current.'),
            'guarantees' => $schema->anyOf([$schema->array()->items($schema->object([
                'role' => $schema->string()->enum(['programme', 'specialisation'])->description('Guarantor of the whole study programme, or of one specialisation within it. One person often holds both for the same programme.'),
                'faculty' => $nullableString()->description('Faculty code, e.g. "FAV".'),
                'programme_code' => $nullableString()->description('Accredited programme code, e.g. "B0613A140015".'),
                'programme_name' => $nullableString()->description('Programme name in Czech.'),
                'specialisation_code' => $nullableString()->description('Specialisation abbreviation, e.g. "INF18bp". Null for a programme guarantor.'),
                'specialisation_name' => $nullableString()->description('Specialisation name in Czech. Null for a programme guarantor.'),
            ]))])->nullable()->description('Study programmes this person guarantees (garant) this academic year. Empty for most people. Null when STAG refused the lookup, so unknown rather than none.'),
            'subjects_year' => $schema->anyOf([$schema->integer()])->nullable()->description('Starting year of the academic year the subjects list covers, e.g. 2026 for 2026/2027. Null when STAG listed no subjects and no year was requested, so the current year is not known from the response.'),
            'subjects' => $schema->anyOf([$schema->array()->items($schema->object([
                'katedra' => $nullableString()->description('Department abbreviation of the subject, e.g. "KIV". Pass it to get-predmet-info with zkratka.'),
                'zkratka' => $nullableString()->description('Subject abbreviation, e.g. "PC".'),
                'nazev' => $nullableString()->description('Subject name, in Czech.'),
                'roles' => $schema->array()->items($schema->object([
                    'role' => $schema->string()->enum(['guarantor', 'lecturer', 'instructor', 'seminar_leader', 'examiner'])->description('guarantor (garant), lecturer (přednášející), instructor (cvičící, runs the exercises), seminar_leader (seminárník) or examiner (zkoušející).'),
                    'share' => $schema->anyOf([$schema->integer()])->nullable()->description('Percentage of that role\'s teaching this person carries, when several people share it. Null for examiner, which has none, or when STAG recorded none.'),
                ]))->description('Roles held on this subject. Can be empty when STAG lists the subject with every flag off.'),
            ]))])->nullable()->description('Subjects this person teaches in subjects_year, including student teachers whose timetable may be empty. Empty when they teach nothing that year. Null when STAG refused the lookup, so unknown rather than none.'),
        ];
    }

    /**
     * Handle the tool request.
     *
     * @throws StagException
     */
    protected function handleAsStagUser(Request $request, StagHttpClient $stag): ResponseFactory|Response
    {
        $validated = $request->validate([
            'teacher_id' => ['required', 'integer', 'min:1'],
            'year' => ['nullable', 'integer', 'min:1990', 'max:2100'],
        ]);

        $teacherId = (int) $validated['teacher_id'];

        // STAG returns [] (an empty list, not an object) for an unknown ucitIdno.
        $info = $stag->get('ucitel/getUcitelInfo', ['ucitIdno' => $teacherId]);

        if ($info === [] || array_is_list($info)) {
            return Response::error("No teacher found with teacher_id {$teacherId}. Look the person up with search-ucitele to get a valid id.");
        }

        $card = BusinessCard::fetch($stag, $teacherId);

        return Response::structured([
            'teacher_id' => (int) $info['ucitIdno'],
            'name' => $this->displayName($info),
            'first_name' => $this->nullIfBlank($info['jmeno'] ?? null),
            'last_name' => $this->nullIfBlank($info['prijmeni'] ?? null),
            'titles_before' => $this->nullIfBlank($info['titulPred'] ?? null),
            'titles_after' => $this->nullIfBlank($info['titulZa'] ?? null),
            'department' => $this->nullIfBlank($info['katedra'] ?? null),
            'other_department' => $this->nullIfBlank($info['pracovisteDalsi'] ?? null),
            'active' => $this->fromStagBool($info['platnost'] ?? null) ?? false,
            'employee' => $this->fromStagBool($info['zamestnanec'] ?? null) ?? false,
            'email' => $this->nullIfBlank($info['email'] ?? null),
            'phones' => $this->phones($info),
            'website' => $card->website() ?? $this->nullIfBlank($info['url'] ?? null),
            'offices' => $this->offices($card, $info),
            'office_hours_note' => $this->officeHoursNote($card, $info),
            'card_updated' => $card->updated(),
            'guarantees' => $this->guarantees($stag, $teacherId),
            ...$this->subjects($stag, $teacherId, isset($validated['year']) ? (int) $validated['year'] : null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function displayName(array $info): string
    {
        $fullName = implode(' ', array_filter([
            $this->nullIfBlank($info['titulPred'] ?? null),
            $this->nullIfBlank($info['jmeno'] ?? null),
            $this->nullIfBlank($info['prijmeni'] ?? null),
        ]));
        $titlesAfter = $this->nullIfBlank($info['titulZa'] ?? null);

        return $titlesAfter !== null ? "{$fullName}, {$titlesAfter}" : $fullName;
    }

    /**
     * Passed through verbatim and never normalised: STAG's telefon is free text
     * holding extensions, full numbers, mobiles and even room codes, so any
     * prefixing would invent wrong numbers. The business card's per-line
     * extensions are left out for the same reason StudyAdvisorTool drops them.
     *
     * @param  array<string, mixed>  $info
     * @return list<string>
     */
    private function phones(array $info): array
    {
        $phones = array_filter([
            $this->nullIfBlank($info['telefon'] ?? null),
            $this->nullIfBlank($info['telefon2'] ?? null),
        ]);

        return array_values(array_unique($phones));
    }

    /**
     * The card is preferred, since its room rides on a phone line someone
     * maintains. Without a card, `getUcitelInfo`'s own building and room are
     * the fallback; they are sparse, but agree with the card whenever both
     * are present.
     *
     * @param  array<string, mixed>  $info
     * @return list<array<string, ?string>>
     */
    private function offices(BusinessCard $card, array $info): array
    {
        if ($card->exists()) {
            return $card->offices();
        }

        $building = $this->nullIfBlank($info['zkrBudovy'] ?? null);
        $room = $this->nullIfBlank($info['cisloMistnosti'] ?? null);

        if ($building === null && $room === null) {
            return [];
        }

        return [['building' => $building, 'room' => $room, 'workplace' => $this->nullIfBlank($info['katedra'] ?? null)]];
    }

    /**
     * Same call as StudyAdvisorTool::officeHoursNote(): STAG does hold
     * consultation hours on the business card, but they are recorded rather
     * than observed behaviour. A third of the university writes them off in
     * their own note ("mail me first"), and a stale slot reported as fact
     * sends a student to a locked door.
     *
     * @param  array<string, mixed>  $info
     */
    private function officeHoursNote(BusinessCard $card, array $info): string
    {
        $note = 'STAG\'s copy of consultation hours (konzultační hodiny) is not reliably maintained, and many teachers ask to be emailed to arrange a time instead.';

        $website = $card->website() ?? $this->nullIfBlank($info['url'] ?? null);

        if ($website !== null) {
            return $note." Check their web page ({$website}) or their department's website, or email them.";
        }

        $email = (string) ($info['email'] ?? '');
        $site = Str::after($email, '@');

        if (filled($site) && $site !== $email) {
            return $note." Check their department's website (under {$site}), or email them.";
        }

        return $note.' Check their department\'s website, or ask them directly.';
    }

    /**
     * Degrades to null rather than failing the tool, so a refused lookup reads
     * as "unknown" instead of "guarantees nothing".
     *
     * @return list<array<string, ?string>>|null
     */
    private function guarantees(StagHttpClient $stag, int $teacherId): ?array
    {
        try {
            $rows = $stag->get('ucitel/getGarant_ProgramOborByUcitIdno', ['ucitIdno' => $teacherId]);
        } catch (StagException) {
            return null;
        }

        return array_map(fn (array $row) => [
            'role' => ($row['typ'] ?? null) === 'O' ? 'specialisation' : 'programme',
            'faculty' => $this->nullIfBlank($row['fakulta'] ?? null),
            'programme_code' => $this->nullIfBlank($row['kodProgramu'] ?? null),
            'programme_name' => $this->nullIfBlank($row['nazevProgramu'] ?? null),
            'specialisation_code' => $this->nullIfBlank($row['zkratkaOboru'] ?? null),
            'specialisation_name' => $this->nullIfBlank($row['nazevOboru'] ?? null),
        ], array_is_list($rows) ? $rows : []);
    }

    /**
     * `rok` is left off the call when no year was asked for, so STAG picks the
     * current academic year itself. Like the guarantees, a refused call degrades
     * to null (unknown) instead of failing the tool or claiming "none".
     *
     * The `*Podil` shares read 100 even on roles the person does not hold (a
     * pure instructor still shows `prednasejiciPodil: 100`), so a share is only
     * reported next to a role whose ANO/NE flag is set.
     *
     * @return array{subjects_year: ?int, subjects: list<array<string, mixed>>|null}
     */
    private function subjects(StagClient $stag, int $teacherId, ?int $year): array
    {
        $query = ['ucitIdno' => $teacherId];

        if ($year !== null) {
            $query['rok'] = $year;
        }

        try {
            $rows = $stag->get('predmety/getPredmetyByUcitel', $query)['predmetUcitele'] ?? [];
        } catch (StagException) {
            return ['subjects_year' => $year, 'subjects' => null];
        }

        $rows = array_is_list($rows) ? $rows : [];

        return [
            'subjects_year' => $year ?? (isset($rows[0]['rok']) ? (int) $rows[0]['rok'] : null),
            'subjects' => array_map(fn (array $row) => [
                'katedra' => $this->nullIfBlank($row['katedra'] ?? null),
                'zkratka' => $this->nullIfBlank($row['zkratka'] ?? null),
                'nazev' => $this->nullIfBlank($row['nazev'] ?? null),
                'roles' => $this->subjectRoles($row),
            ], $rows),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<array{role: string, share: ?int}>
     */
    private function subjectRoles(array $row): array
    {
        $roles = [];

        foreach (self::SUBJECT_ROLES as $role => [$flag, $shareField]) {
            if ($this->fromStagBool($row[$flag] ?? null) !== true) {
                continue;
            }

            $share = $shareField !== null ? ($row[$shareField] ?? null) : null;
            $roles[] = ['role' => $role, 'share' => $share !== null ? (int) $share : null];
        }

        return $roles;
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

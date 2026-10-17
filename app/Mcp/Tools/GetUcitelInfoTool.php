<?php

namespace App\Mcp\Tools;

use App\Contracts\StagClient;
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
#[Description('Full detail for one teacher or staff member by teacher_id: name and titles, department, email and phone, which office they sit in, their web page, and which study programmes they guarantee. Find the teacher_id with search-ucitele first. Student teachers (demonstrators, doctoral assistants) often have no office or phone recorded, so email is the only contact STAG holds for them.')]
#[IsReadOnly]
class GetUcitelInfoTool extends Tool
{
    use RequiresStagLogin;

    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fucitel
    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fvizitka

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'teacher_id' => $schema->integer()
                ->description('The teacher_id (ucitIdno) from search-ucitele, or from any subject or timetable tool that lists teachers.')
                ->required(),
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
        ];
    }

    /**
     * Handle the tool request.
     *
     * @throws StagException
     */
    protected function handleForStagUser(Request $request, StagClient $stag): ResponseFactory|Response
    {
        $validated = $request->validate([
            'teacher_id' => ['required', 'integer', 'min:1'],
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
    private function guarantees(StagClient $stag, int $teacherId): ?array
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

<?php

namespace App\Mcp\Tools;

use App\Contracts\StagClient;
use App\Exceptions\StagException;
use App\Mcp\Concerns\RequiresStagLogin;
use App\Mcp\Concerns\ResolvesStagIdentity;
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

#[Name('get-study-advisor')]
#[Title('Get Study Advisor')]
#[Description("The caller's study advisor (studijní referentka): the person to contact about enrolment, study plans, interruptions, transcripts and paperwork. Returns their name, email and phone, which office they sit in, and their published office hours. Not the same as a teacher or a supervisor - for those use who-am-i or the subject tools.")]
#[IsReadOnly]
class StudyAdvisorTool extends Tool
{
    use RequiresStagLogin;
    use ResolvesStagIdentity;

    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fstudent
    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fvizitka

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'os_cislo' => $schema->string()
                ->description('Personal number (osCislo) whose advisor to look up, for accounts holding more than one STAG role. Resolved automatically when omitted; call who-am-i to see the roles an account holds.'),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $nullableString = fn () => $schema->anyOf([$schema->string()])->nullable();

        return [
            'os_cislo' => $schema->string()->description('The study this advisor belongs to.'),
            'advisor' => $schema->anyOf([$schema->object([
                'name' => $nullableString()->description('Surname first, with titles appended, as STAG stores it.'),
                'email' => $nullableString()->description('Work email. Usually the fastest way to reach them.'),
                'phone' => $nullableString()->description('Phone exactly as STAG stores it, which is a free-text field. For study-office staff this is a full dialable number; teaching staff often put a four-digit internal extension, or occasionally something that is not a phone number at all, in the same field. Do not assume it can be dialled from outside the university without looking at it.'),
                'offices' => $schema->array()->description('Where they sit. Usually one entry; more when they hold phone lines in different rooms. Empty when no business card is published.')
                    ->items($schema->object([
                        'building' => $nullableString()->description('Building abbreviation, e.g. "UC". Resolve it with list-budovy for an address.'),
                        'room' => $nullableString()->description('Room number within the building.'),
                        'workplace' => $nullableString()->description('Workplace abbreviation, e.g. "DAV" for the FAV dean\'s office.'),
                    ])),
                /*
                 * There are ways to get office_hours from `teacher_id` and via `vizitka/getUredniHodinyPracoviste`,
                 * however most entries don't have any set and if they do I don't trust it as no-one reads this
                 * on STAG and everyone reads this on the website, so I opted to leave just a note for the agent
                 * to find it via web_search rather than have potentially wrong data surfaced to the user.
                 */
                'office_hours_note' => $schema->string()->description('Where to find this person\'s úřední hodiny.'),
                'card_updated' => $nullableString()->description('When the business card was last edited, ISO date. These are often years old, so treat the room number as unconfirmed rather than current.'),
            ])])->description('The study advisor, or null when STAG has no referentka recorded for this study.')->nullable(),
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
            'os_cislo' => ['string', 'nullable'],
        ]);

        $osCislo = $validated['os_cislo'] ?? $this->resolveOsCislo($stag);

        if ($osCislo === null) {
            return Response::error('Could not resolve an osCislo for this account. Pass os_cislo explicitly, or call who-am-i to see which roles it holds.');
        }

        $info = $stag->get('student/getStudentInfo', ['osCislo' => $osCislo]);

        return Response::structured([
            'os_cislo' => $info['osCislo'] ?? $osCislo,
            'advisor' => $this->toAdvisor($info, $stag),
        ]);
    }

    /**
     * @param  array<string, mixed>  $info
     * @return array<string, mixed>|null
     *
     * @throws StagException
     */
    private function toAdvisor(array $info, StagClient $stag): ?array
    {
        if (blank($info['studReferentkaPrijmeniJmeno'] ?? null)) {
            return null;
        }

        /*
         * Not returned: nothing this server exposes accepts a ucitIdno, and it
         * means nothing to a reader. It is fetched because it is the only key
         * the business card can be looked up by.
         */
        $teacherId = $info['studReferentkaUcitidno'] ?? null;
        $card = $teacherId === null ? null : $this->fetchCard($stag, $teacherId);

        return [
            'name' => $info['studReferentkaPrijmeniJmeno'],
            'email' => $info['studReferentkaEmail'] ?? null,
            'phone' => $info['studReferentkaTelefon'] ?? null,
            'offices' => $this->toOffices($card),
            'office_hours_note' => $this->officeHoursNote($info),
            'card_updated' => $card['info']['update'] ?? null,
        ];
    }

    /**
     * The business card, which is the only place STAG records where someone
     * sits: `getUcitelInfo`'s own zkrBudovy and cisloMistnosti are null even
     * for staff whose card carries a building and room. Its `uredniHodiny`
     * block is read and discarded on purpose, see officeHoursNote() below.
     *
     * Degrades to null rather than failing the tool, so a refused or missing
     * card still leaves the name, email and phone answerable.
     *
     * @return array<string, mixed>|null
     */
    private function fetchCard(StagClient $stag, string $teacherId): ?array
    {
        try {
            $card = $stag->get('vizitka/getVizitkaByUcitidno', ['ucitidno' => $teacherId]);
        } catch (StagException) {
            return null;
        }

        return $card === [] ? null : $card;
    }

    /**
     * STAG lists one phone-directory row per line and repeats the room on each,
     * so a person with two lines in one office yields two identical locations.
     *
     * @param  array<string, mixed>|null  $card
     * @return array<int, array<string, mixed>>
     */
    private function toOffices(?array $card): array
    {
        $offices = [];

        foreach ($card['tsLinky']['linky'] ?? [] as $line) {
            $office = [
                'building' => $line['budova'] ?? null,
                'room' => $line['mistnost'] ?? null,
                'workplace' => $line['zkratkaPracoviste'] ?? null,
            ];

            $offices[implode('|', array_map(strval(...), $office))] = $office;
        }

        return array_values($offices);
    }

    /**
     * Where the office hours actually live.
     *
     * The faculty site is derived from the advisor's own email domain rather
     * than a lookup table, so it stays right for faculties never tested here.
     *
     * @param  array<string, mixed>  $info
     */
    private function officeHoursNote(array $info): string
    {
        $note = 'Every study office keeps úřední hodiny and publishes them on its faculty website.';

        $site = Str::after((string) ($info['studReferentkaEmail'] ?? ''), '@');

        if (filled($site) && $site !== ($info['studReferentkaEmail'] ?? null)) {
            return $note." Look on {$site}, or search the web for the study office (studijní oddělení) there.";
        }

        return $note.' Search the web for that faculty\'s study office (studijní oddělení).';
    }
}

<?php

namespace App\Mcp\Tools;

use App\Clients\StagClient;
use App\Exceptions\StagException;
use App\Mcp\Enums\CalendarPeriod;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-harmonogram')]
#[Title('Get Harmonogram')]
#[Description("Academic year milestones: start/end of semesters, exam periods, and teaching-free days. Use get-kalendar for the caller's actual timetable on a given date.")]
#[IsReadOnly]
class GetHarmonogramTool extends Tool
{
    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fkalendar

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'year' => $schema->string()
                ->description('Academic year, e.g. "2026". Defaults to the current STAG academic year when omitted.'),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $nullableString = fn () => $schema->anyOf([$schema->string()])->nullable();

        $period = fn (string $description) => $schema->object([
            'code' => $schema->string()->description('Raw STAG code, e.g. "ZR".'),
            'label' => $schema->string()->description('English label for the code.'),
        ])->description($description);

        return [
            'current' => $schema->object([
                'period' => $period('The period STAG considers current right now.'),
                'academic_year' => $schema->string()->description('The academic year STAG considers current right now.'),
                'semester' => $period("STAG's best guess at the semester meant right now. Differs from period during preparation and holiday weeks."),
            ]),
            'total' => $schema->integer()->description('How many entries were returned.'),
            'entries' => $schema->array()
                ->description('Harmonogram entries plus derived milestones, sorted ascending by date_from.')
                ->items($schema->object([
                    'date_from' => $schema->string()->description('ISO date the entry starts.'),
                    'date_to' => $nullableString()->description('ISO date the entry ends, or null. Always null on every entry observed so far; STAG has not populated this field.'),
                    'description' => $schema->string()->description("The entry's description. Harmonogram rows keep STAG's Czech text as-is; the derived milestones are given in English."),
                ])),
        ];
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, #[CurrentUser('sanctum')] ?User $user = null): ResponseFactory|Response
    {
        if ($user === null) {
            return Response::error('No authenticated user. The MCP client must send a bearer token.');
        }

        $validated = $request->validate([
            'year' => ['string', 'nullable'],
        ]);

        $stag = new StagClient($user);

        try {
            // Reflects "now" regardless of the requested year, so it is
            // fetched once and not affected by the 'year' filter below.
            $obdobi = $stag->get('kalendar/getAktualniObdobiInfo');

            $params = array_filter(['rok' => $validated['year'] ?? null], fn ($value) => $value !== null);
            $harmonogram = $stag->get('kalendar/getHarmonogramRoku', $params);
        } catch (StagException $e) {
            return Response::error($e->getMessage());
        }

        $entries = $this->toEntries($harmonogram['harmonogramItem'] ?? [], $obdobi);

        usort($entries, fn (array $a, array $b) => $a['date_from'] <=> $b['date_from']);

        return Response::structured([
            'current' => [
                'period' => $this->toPeriod($obdobi['obdobi'] ?? null),
                'academic_year' => $obdobi['akademRokInteligentne'] ?? null,
                'semester' => $this->toPeriod($obdobi['semestrInteligentne'] ?? null),
            ],
            'total' => count($entries),
            'entries' => $entries,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $harmonogramItems
     * @param  array<string, mixed>  $obdobi
     * @return list<array{date_from: string, date_to: ?string, description: string}>
     */
    private function toEntries(array $harmonogramItems, array $obdobi): array
    {
        return array_merge(
            array_map($this->harmonogramRowToEntry(...), $harmonogramItems),
            $this->obdobiToEntries($obdobi),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{date_from: string, date_to: ?string, description: string}
     */
    private function harmonogramRowToEntry(array $row): array
    {
        return [
            'date_from' => $this->toIsoDate($row['datumOd']['value'] ?? null),
            'date_to' => $this->toIsoDate($row['datumDo']['value'] ?? null),
            'description' => $row['popis'],
        ];
    }

    /**
     * A couple of these can land on the same date as a harmonogram row (e.g.
     * "Start of the academic year" next to the harmonogram's own "Začátek ak.
     * roku"). That's left as-is rather than deduped against the harmonogram:
     * one extra line is cheaper than coupling this to the harmonogram's exact
     * wording.
     *
     * @param  array<string, mixed>  $obdobi
     * @return list<array{date_from: string, date_to: ?string, description: string}>
     */
    private function obdobiToEntries(array $obdobi): array
    {
        $fields = [
            'posledniVyucovaciDenRoku' => 'Last teaching day of the year',
            'posledniDenSemestruInteligentne' => 'Last day of the current semester',
            'posledniDenZimnihoZkouskoveho' => 'Last day of the winter exam period',
            'posledniDenLetnihoZkouskoveho' => 'Last day of the summer exam period',
            'prvniDenStavajicihoAkademickehoRoku' => 'Start of the academic year',
            'posledniDenStavajicihoAkademickehoRoku' => 'End of the academic year',
        ];

        $entries = [];

        foreach ($fields as $field => $description) {
            $date = $this->toIsoDate($obdobi[$field]['value'] ?? null);

            if ($date !== null) {
                $entries[] = ['date_from' => $date, 'date_to' => null, 'description' => $description];
            }
        }

        return $entries;
    }

    private function toIsoDate(?string $value): ?string
    {
        return $value !== null ? Carbon::createFromFormat('j.n.Y', $value)->toDateString() : null;
    }

    /**
     * @return array{code: string, label: string}|null
     */
    private function toPeriod(?string $code): ?array
    {
        if ($code === null) {
            return null;
        }

        return [
            'code' => $code,
            'label' => CalendarPeriod::tryFrom($code)?->label() ?? $code,
        ];
    }
}

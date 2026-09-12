<?php

namespace App\Mcp\Tools;

use App\Clients\StagClient;
use App\Exceptions\StagException;
use App\Mcp\Concerns\RequiresStagLogin;
use App\Mcp\Concerns\ResolvesStagIdentity;
use App\Mcp\Enums\CalendarPeriod;
use App\Mcp\Enums\Weekday;
use App\Mcp\Enums\WeekParity;
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

#[Name('get-kalendar')]
#[Title('Get Kalendar')]
#[Description("The caller's timetable over a date range: classes, seminars, exams and zápočty, each day annotated with the odd/even week marker and whether it's a teaching day. STAG silently omits occurrences that do not happen (holidays, rector's days, ad-hoc cancellations) rather than marking them cancelled, so a day with no events means nothing was scheduled, not that the tool failed to find it.")]
#[IsReadOnly]
class GetKalendarTool extends Tool
{
    use RequiresStagLogin;
    use ResolvesStagIdentity;

    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Frozvrhy
    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fkalendar

    /** How many days to return when count is not given. */
    private const int DEFAULT_COUNT = 100;

    /** Upper bound on count, so one call cannot return years of days at once. */
    private const int MAX_COUNT = 500;

    /** Upper bound on date_from..date_to, so one call cannot pull several years of timetable. */
    private const int MAX_RANGE_DAYS = 400;

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'date_from' => $schema->string()
                ->description('First date to include, ISO Y-m-d. Defaults to today.'),
            'date_to' => $schema->string()
                ->description('Last date to include, ISO Y-m-d. Defaults to date_from + 13 days.'),
            'os_cislo' => $schema->string()
                ->description('Personal number (osCislo) to fetch the timetable for, for accounts holding more than one STAG role. Resolved automatically via help/getStagUserListForActualUser when omitted.'),
            'include_empty_days' => $schema->boolean()
                ->description('Include every day in the range, even ordinary teaching days with nothing scheduled. Off by default: only days carrying an event, or a reason nothing is scheduled (holiday, exam period, ...), are returned.')
                ->default(false),
            'count' => $schema->integer()
                ->description('How many days to return, up to '.self::MAX_COUNT.'.')
                ->min(1)->max(self::MAX_COUNT)->default(self::DEFAULT_COUNT),
            'offset' => $schema->integer()
                ->description('How many matching days to skip. Use with total from the previous response to page through the rest.')
                ->min(0)->default(0),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $nullableString = fn () => $schema->anyOf([$schema->string()])->nullable();
        $nullableInt = fn () => $schema->anyOf([$schema->integer()])->nullable();

        return [
            'os_cislo' => $schema->string()->description('The osCislo this timetable was fetched for.'),
            'date_from' => $schema->string()->description('First date of the range, ISO.'),
            'date_to' => $schema->string()->description('Last date of the range, ISO.'),
            'total' => $schema->integer()->description('Total matching days, before paging.'),
            'offset' => $schema->integer()->description('The offset that was applied.'),
            'count' => $schema->integer()->description('How many days are in this page.'),
            'days' => $schema->array()
                ->description('Matching days, ascending.')
                ->items($schema->object([
                    'date' => $schema->string()->description('ISO date.'),
                    'weekday' => $schema->string()->description("The date's real weekday, computed independently of STAG's timetable-day code."),
                    'timetable_day' => $nullableString()->description('STAG\'s timetable weekday code (English), e.g. "Tuesday". Null on Svátek/Rektorský den, when it is not a real weekday.'),
                    'timetable_week' => $nullableString()->description('Odd/even week marker for this specific day: "odd", "even", "every", or "other". Read per day, not derived from week_number.'),
                    'week_number' => $nullableInt()->description('ISO-ish week number STAG assigns, or null.'),
                    'period' => $schema->anyOf([
                        $schema->object([
                            'code' => $schema->string()->description('Raw STAG code, e.g. "ZS".'),
                            'label' => $schema->string()->description('English label for the code.'),
                        ]),
                    ])->description('The academic-year period this day falls in, or null.')->nullable(),
                    'teaching' => $schema->boolean()->description('False when the timetable-day code is Svátek/Rektorský den, or the period is not an in-semester one.'),
                    'non_teaching_reason' => $nullableString()->description('Why teaching is false on this day, or null when teaching is true.'),
                    'events' => $schema->array()
                        ->description('Classes and exams occurring on this day. STAG omits occurrences that do not happen, so this can be empty even on a teaching day.')
                        ->items($schema->object([
                            'kind' => $schema->string()->enum(['class', 'exam'])->description('"class" for lectures/seminars, "exam" for exams and zápočty.'),
                            'type' => $schema->string()->description('STAG\'s own type label, e.g. "Přednáška", "Cvičení", "Zkouška", "Zápočet".'),
                            'subject' => $schema->object([
                                'katedra' => $schema->string()->description('Department abbreviation.'),
                                'zkratka' => $schema->string()->description('Subject abbreviation.'),
                                'nazev' => $schema->string()->description('Subject name.'),
                            ]),
                            'time' => $schema->object([
                                'from' => $nullableString()->description('Start time, HH:MM, or null.'),
                                'to' => $nullableString()->description('End time, HH:MM, or null.'),
                            ]),
                            'location' => $schema->object([
                                'building' => $nullableString()->description('Building abbreviation, or null.'),
                                'room' => $nullableString()->description('Room number, or null.'),
                            ]),
                            'teachers' => $schema->array()
                                ->description('Teachers on this occurrence.')
                                ->items($schema->object([
                                    'ucit_idno' => $nullableInt()->description('Teacher id, or null.'),
                                    'name' => $nullableString()->description('Teacher name with titles, or null.'),
                                ])),
                            'week' => $nullableString()->description('Which weeks this occurrence runs on: "odd", "even", "every", or "other".'),
                            'note' => $nullableString()->description('STAG\'s free-text override for this occurrence, with the leading date stripped. Can contradict the structured location (e.g. a room change), and is not a cancellation flag.'),
                            'capacity' => $schema->object([
                                'room' => $nullableInt()->description('Room capacity, or null.'),
                                'planned' => $nullableInt()->description('Planned enrolment, or null.'),
                                'enrolled' => $nullableInt()->description('Actual enrolment, or null.'),
                            ]),
                        ])),
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
        $validated = $request->validate([
            'date_from' => ['date_format:Y-m-d', 'nullable'],
            'date_to' => ['date_format:Y-m-d', 'nullable'],
            'os_cislo' => ['string', 'nullable'],
            'include_empty_days' => ['boolean', 'nullable'],
            'count' => ['integer', 'min:1', 'max:'.self::MAX_COUNT, 'nullable'],
            'offset' => ['integer', 'min:0', 'nullable'],
        ]);

        $dateFrom = isset($validated['date_from']) ? Carbon::parse($validated['date_from']) : Carbon::today();
        $dateTo = isset($validated['date_to']) ? Carbon::parse($validated['date_to']) : $dateFrom->copy()->addDays(13);

        if ($dateFrom->gt($dateTo)) {
            return Response::error('date_from must not be after date_to.');
        }

        if ($dateFrom->diffInDays($dateTo) > self::MAX_RANGE_DAYS) {
            return Response::error('The range between date_from and date_to cannot exceed '.self::MAX_RANGE_DAYS.' days.');
        }

        $osCislo = $validated['os_cislo'] ?? $this->resolveOsCislo($stag);

        if ($osCislo === null) {
            return Response::error('Could not resolve an osCislo for this account. Pass os_cislo explicitly.');
        }

        $rozvrh = $stag->get('rozvrhy/getRozvrhByStudent', [
            'osCislo' => $osCislo,
            'datumOd' => $dateFrom->format('j.n.Y'),
            'datumDo' => $dateTo->format('j.n.Y'),
            'vsechnyAkce' => 'true',
        ]);

        $eventsByDate = [];
        foreach ($rozvrh['rozvrhovaAkce'] ?? [] as $row) {
            $date = $this->toIsoDate($row['datum']['value'] ?? null);

            if ($date !== null) {
                $eventsByDate[$date][] = $row;
            }
        }

        $kalendarByDate = $this->fetchKalendarRange($stag, $dateFrom, $dateTo);
        $includeEmptyDays = $validated['include_empty_days'] ?? false;

        $days = [];

        for ($date = $dateFrom->copy(); $date->lte($dateTo); $date->addDay()) {
            $iso = $date->toDateString();
            $events = $eventsByDate[$iso] ?? [];
            $day = $this->toDay($date, $events, $kalendarByDate[$iso] ?? null);

            if ($includeEmptyDays || $events !== [] || ! $day['teaching']) {
                $days[] = $day;
            }
        }

        $offset = $validated['offset'] ?? 0;
        $count = $validated['count'] ?? self::DEFAULT_COUNT;
        $page = array_slice($days, $offset, $count);

        return Response::structured([
            'os_cislo' => $osCislo,
            'date_from' => $dateFrom->toDateString(),
            'date_to' => $dateTo->toDateString(),
            'total' => count($days),
            'offset' => $offset,
            'count' => count($page),
            'days' => $page,
        ]);
    }

    /**
     * getKalendarRoku is fetched per academic year (1.9-31.8) the range touches,
     * then filtered to the range client-side, since it does not take a date-range
     * parameter of its own.
     *
     * @return array<string, array<string, mixed>> ISO date => kalendarItem row
     *
     * @throws StagException
     */
    private function fetchKalendarRange(StagClient $stag, Carbon $from, Carbon $to): array
    {
        $years = array_unique([$this->academicYearFor($from), $this->academicYearFor($to)]);

        $byDate = [];

        foreach ($years as $year) {
            $rows = $stag->get('kalendar/getKalendarRoku', ['rok' => (string) $year]);

            foreach ($rows['kalendarItem'] ?? [] as $row) {
                $iso = $this->toIsoDate($row['datum']['value'] ?? null);

                if ($iso !== null) {
                    $byDate[$iso] = $row;
                }
            }
        }

        return $byDate;
    }

    private function academicYearFor(Carbon $date): int
    {
        return $date->month >= 9 ? $date->year : $date->year - 1;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  array<string, mixed>|null  $kalendarRow
     * @return array<string, mixed>
     */
    private function toDay(Carbon $date, array $events, ?array $kalendarRow): array
    {
        $rozvrhDen = $kalendarRow['rozvrhDen'] ?? null;
        $typRozvrhDne = $kalendarRow['typRozvrhDne'] ?? null;
        $typTydne = $kalendarRow['typTydne'] ?? null;

        // Sv (Svátek) and Rd (Rektorský den) replace the weekday code entirely,
        // so the real weekday cannot be recovered from rozvrhDen on those days.
        $isNonTeachingDayCode = in_array($rozvrhDen, ['Sv', 'Rd'], true);
        $period = $typRozvrhDne !== null ? CalendarPeriod::tryFrom($typRozvrhDne) : null;
        $teaching = ! $isNonTeachingDayCode && ($period?->isTeachingPeriod() ?? false);

        $nonTeachingReason = match (true) {
            $teaching => null,
            $rozvrhDen === 'Sv' => 'Public holiday',
            $rozvrhDen === 'Rd' => "Rector's day",
            $period !== null => $period->label(),
            default => null,
        };

        return [
            'date' => $date->toDateString(),
            'weekday' => $date->englishDayOfWeek,
            'timetable_day' => (! $isNonTeachingDayCode && $rozvrhDen !== null) ? Weekday::tryFrom($rozvrhDen)?->label() : null,
            'timetable_week' => $typTydne !== null ? $this->weekParityLabel($typTydne) : null,
            'week_number' => $kalendarRow['cisloTydne'] ?? null,
            'period' => $typRozvrhDne !== null ? ['code' => $typRozvrhDne, 'label' => $period?->label() ?? $typRozvrhDne] : null,
            'teaching' => $teaching,
            'non_teaching_reason' => $nonTeachingReason,
            'events' => array_map($this->toEvent(...), $events),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function toEvent(array $row): array
    {
        return [
            'kind' => ($row['druhAkce'] ?? null) === 'Z' ? 'exam' : 'class',
            'type' => $row['typAkce'] ?? null,
            'subject' => [
                'katedra' => $row['katedra'] ?? null,
                'zkratka' => $row['predmet'] ?? null,
                'nazev' => $row['nazev'] ?? null,
            ],
            'time' => [
                'from' => $row['hodinaSkutOd']['value'] ?? null,
                'to' => $row['hodinaSkutDo']['value'] ?? null,
            ],
            'location' => [
                'building' => $row['budova'] ?? null,
                'room' => $row['mistnost'] ?? null,
            ],
            'teachers' => $this->toTeachers($row),
            'week' => isset($row['tydenZkr']) ? $this->weekParityLabel($row['tydenZkr']) : null,
            'note' => $this->stripNoteDate($row['nekonaSe'] ?? null),
            'capacity' => [
                'room' => $row['kapacitaMistnosti'] ?? null,
                'planned' => $row['planObsazeni'] ?? null,
                'enrolled' => $row['obsazeni'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<array{ucit_idno: ?int, name: ?string}>
     */
    private function toTeachers(array $row): array
    {
        $ids = $this->splitIds($row['vsichniUciteleUcitIdno'] ?? null);
        $names = $this->extractQuotedNames($row['vsichniUciteleJmenaTitulySPodily'] ?? null);

        return array_map(
            fn (?int $id, ?string $name) => ['ucit_idno' => $id, 'name' => $name],
            $ids,
            $names,
        );
    }

    /**
     * @return list<int>
     */
    private function splitIds(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_map(fn (string $entry) => (int) trim($entry), explode(',', $value));
    }

    /**
     * vsichniUciteleJmenaTituly looks like a quoted list but is not: its entries
     * are plain comma-separated, so a title like "Ing. X, Ph.D." breaks a naive
     * split. vsichniUciteleJmenaTitulySPodily is quoted per entry (plus a
     * trailing workload percentage), so names are pulled from there instead of
     * splitting on a quote-comma-quote boundary, since entries end in "(N)"
     * rather than directly abutting the next quote.
     *
     * @return list<string>
     */
    private function extractQuotedNames(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        preg_match_all("/'([^']*)'/u", $value, $matches);

        return $matches[1];
    }

    /**
     * nekonaSe is formatted "d.M.yyyy: <label> (<detail>)"; the leading date is
     * redundant with the event's own date field.
     */
    private function stripNoteDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_replace('/^\d{1,2}\.\d{1,2}\.\d{4}:\s*/u', '', $value);
    }

    /**
     * Falls back to the raw code for a value STAG's TYDEN domain doesn't
     * cover, rather than dropping it.
     */
    private function weekParityLabel(string $code): string
    {
        return WeekParity::tryFrom($code)?->label() ?? $code;
    }

    private function toIsoDate(?string $value): ?string
    {
        return $value !== null ? Carbon::createFromFormat('j.n.Y', $value)->toDateString() : null;
    }
}

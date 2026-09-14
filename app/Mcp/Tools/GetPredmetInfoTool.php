<?php

namespace App\Mcp\Tools;

use App\Clients\StagHttpClient;
use App\Exceptions\StagException;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
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

#[Name('get-predmet-info')]
#[Title('Get Predmet Info')]
#[Description('Full detail for one STAG subject identified by katedra and zkratka, e.g. from search-predmety: credits, teaching load, exam, staff, syllabus, and related subjects.')]
#[IsReadOnly]
class GetPredmetInfoTool extends Tool
{
    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fpredmety

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'katedra' => $schema->string()
                ->description('Department abbreviation, e.g. "KIV".')
                ->required(),
            'zkratka' => $schema->string()
                ->description('Subject abbreviation, e.g. "UPA".')
                ->required(),
            'rok' => $schema->string()
                ->description('Academic year, e.g. "2026". Defaults to the current STAG year when omitted.'),
            'lang' => $schema->string()
                ->description('Language code for translated fields, e.g. "en". Not validated — passed through as-is.'),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $nullableString = fn () => $schema->anyOf([$schema->string()])->nullable();
        $nullableBool = fn () => $schema->anyOf([$schema->boolean()])->nullable();
        $nullableInt = fn () => $schema->anyOf([$schema->integer()])->nullable();
        $stringList = fn (string $description) => $schema->array()->description($description)->items($schema->string());
        $intList = fn (string $description) => $schema->array()->description($description)->items($schema->integer());

        return [
            'katedra' => $schema->string()->description('Department abbreviation.'),
            'zkratka' => $schema->string()->description('Subject abbreviation.'),
            'rok' => $schema->string()->description('Academic year.'),
            'nazev' => $schema->string()->description('Subject name.'),
            'nazev_dlouhy' => $schema->string()->description('Long subject name.'),
            'akreditovan' => $nullableBool()->description('Whether the subject is accredited, or null.'),
            'uroven' => $nullableString()->description('Computed study level (e.g. "Bc."), or null.'),
            'teaching' => $schema->object([
                'kredity' => $nullableInt()->description('ECTS credits, or null.'),
                'vyuka_zs' => $nullableBool()->description('Taught in winter semester, or null.'),
                'vyuka_ls' => $nullableBool()->description('Taught in summer semester, or null.'),
                'ma_vyuku' => $nullableBool()->description('Currently has teaching, or null.'),
                'jak_casto' => $nullableString()->description('How often the subject is offered (raw STAG code), or null.'),
                'jak_casto_upresneni' => $nullableString()->description('Free-text detail on how often it is offered, or null.'),
                'vice_zapis' => $nullableString()->description('Whether repeated enrolment is allowed (raw STAG value), or null.'),
                'min_obsazeni' => $nullableInt()->description('Minimum enrolment, or null.'),
                'jazyky' => $nullableString()->description('Languages of instruction, or null.'),
            ]),
            'hours' => $schema->object([
                'prednasky' => $schema->object([
                    'pocet' => $nullableInt()->description('Lecture hours, or null.'),
                    'jednotka' => $nullableString()->description('Unit the lecture hours are measured in, or null.'),
                ]),
                'cviceni' => $schema->object([
                    'pocet' => $nullableInt()->description('Seminar/exercise hours, or null.'),
                    'jednotka' => $nullableString()->description('Unit the exercise hours are measured in, or null.'),
                ]),
                'seminare' => $schema->object([
                    'pocet' => $nullableInt()->description('Seminar hours, or null.'),
                    'jednotka' => $nullableString()->description('Unit the seminar hours are measured in, or null.'),
                ]),
                'celkova_narocnost' => $nullableString()->description('Breakdown of total workload by activity, or null.'),
            ]),
            'exam' => $schema->object([
                'typ' => $nullableString()->description('Exam type, or null.'),
                'forma' => $nullableString()->description('Exam form, or null.'),
                'zapocet_pred_zkouskou' => $nullableBool()->description('Whether credit is required before the exam, or null.'),
            ]),
            'people' => $schema->object([
                'garanti' => $stringList('Guarantor names.'),
                'garanti_ucit_idno' => $intList('Guarantor teacher IDs, for cross-referencing with the ucitel namespace.'),
                'prednasejici' => $stringList('Lecturer names.'),
                'prednasejici_ucit_idno' => $intList('Lecturer teacher IDs.'),
                'cvicici' => $stringList('Seminar/exercise instructor names.'),
                'cvicici_ucit_idno' => $intList('Seminar/exercise instructor teacher IDs.'),
                'seminarici' => $stringList('Seminar leader names.'),
                'seminarici_ucit_idno' => $intList('Seminar leader teacher IDs.'),
                'examinatori' => $stringList('Examiner names.'),
                'examinatori_ucit_idno' => $intList('Examiner teacher IDs.'),
                'schvalujici_uznani' => $stringList('Names of those who approve recognition of the subject.'),
                'schvalujici_uznani_ucit_idno' => $intList('Teacher IDs of those who approve recognition.'),
            ]),
            'content' => $schema->object([
                'anotace' => $nullableString()->description('Annotation, or null.'),
                'prehled_latky' => $nullableString()->description('Syllabus overview, or null.'),
                'pozadavky' => $nullableString()->description('Requirements to pass, or null.'),
                'predpoklady' => $nullableString()->description('Prerequisites (free text), or null.'),
                'ziskane_zpusobilosti' => $nullableString()->description('Competencies gained, or null.'),
                'metody_vyucovaci' => $nullableString()->description('Teaching methods, or null.'),
                'metody_hodnotici' => $nullableString()->description('Assessment methods, or null.'),
                'literatura' => $stringList('Literature/reading list entries.'),
                'studijni_opory' => $nullableString()->description('Study materials link or note, or null.'),
            ]),
            'relations' => $schema->object([
                'podminujici' => $stringList('Subjects required as prerequisites (raw codes).'),
                'vylucujici' => $stringList('Mutually exclusive subjects (raw codes).'),
                'podminuje' => $stringList('Subjects this one is a prerequisite for (raw codes).'),
                'nahrazuje' => $stringList('Subjects this one replaces (raw codes).'),
            ]),
            'ects' => $schema->object([
                'zobrazit' => $nullableBool()->description('Whether to display in ECTS, or null.'),
                'akreditace' => $nullableBool()->description('Whether ECTS-accredited, or null.'),
                'nabizet_u_prijezdu' => $nullableBool()->description('Whether offered to incoming ECTS students, or null.'),
            ]),
            'misc' => $schema->object([
                'poznamka' => $nullableString()->description('Internal note, or null.'),
                'poznamka_verejna' => $nullableString()->description('Public note, or null.'),
                'url' => $nullableString()->description('Subject URL, or null.'),
                'skupina_akreditace' => $nullableString()->description('Accreditation group, or null.'),
                'zarazen_prezencni' => $nullableBool()->description('Included in full-time study, or null.'),
                'zarazen_kombinovane' => $nullableBool()->description('Included in combined study, or null.'),
                'praxe_pocet_dnu' => $nullableString()->description('Number of internship days, or null.'),
                'automaticky_uznavat_zpp_zk' => $nullableBool()->description('Whether ZPP/exam is auto-recognised, or null.'),
                'hod_za_sem_komb_forma' => $nullableString()->description('Hours per semester in combined form, or null.'),
            ]),
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
            'katedra' => ['string', 'required'],
            'zkratka' => ['string', 'required'],
            'rok' => ['string', 'nullable'],
            'lang' => ['string', 'nullable'],
        ]);

        $params = array_filter([
            'katedra' => $validated['katedra'],
            'zkratka' => $validated['zkratka'],
            'rok' => $validated['rok'] ?? null,
            'lang' => $validated['lang'] ?? null,
        ], fn ($value) => $value !== null);

        try {
            $row = (new StagHttpClient($user))->get('predmety/getPredmetInfo', $params);
        } catch (StagException $e) {
            return Response::error($e->getMessage());
        }

        // STAG returns [] (an empty list, not an object) when nothing matches
        // katedra/zkratka/rok, rather than a 404 or an error response.
        if ($row === [] || array_is_list($row)) {
            return Response::error("No subject found for katedra '{$validated['katedra']}', zkratka '{$validated['zkratka']}'.");
        }

        return Response::structured($this->toSubjectInfo($row));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function toSubjectInfo(array $row): array
    {
        return [
            'katedra' => $row['katedra'],
            'zkratka' => $row['zkratka'],
            'rok' => $row['rok'],
            'nazev' => $row['nazev'],
            'nazev_dlouhy' => $row['nazevDlouhy'],
            'akreditovan' => $this->fromStagBool($row['akreditovan'] ?? null),
            // urovenNastavena is dropped: null in every sample seen so far and
            // redundant with the computed urovenVypoctena. skupinaAkreditaceKey
            // is dropped too — it's just skupinaAkreditace's numeric code.
            'uroven' => $row['urovenVypoctena'] ?? null,
            'teaching' => [
                'kredity' => $row['kreditu'] ?? null,
                'vyuka_zs' => $this->fromStagBool($row['vyukaZS'] ?? null),
                'vyuka_ls' => $this->fromStagBool($row['vyukaLS'] ?? null),
                'ma_vyuku' => $this->fromStagBool($row['maVyuku'] ?? null),
                'jak_casto' => $row['jakCastoJeNabizen'] ?? null,
                'jak_casto_upresneni' => $row['jakCastoJeNabizenUpresneni'] ?? null,
                'vice_zapis' => $row['viceZapis'] ?? null,
                'min_obsazeni' => $row['minObsazeni'] ?? null,
                'jazyky' => $row['vyucovaciJazyky'] ?? null,
            ],
            'hours' => [
                'prednasky' => [
                    'pocet' => $row['jednotekPrednasek'] ?? null,
                    'jednotka' => $row['jednotkaPrednasky'] ?? null,
                ],
                'cviceni' => [
                    'pocet' => $row['jednotekCviceni'] ?? null,
                    'jednotka' => $row['jednotkaCviceni'] ?? null,
                ],
                'seminare' => [
                    'pocet' => $row['jednotekSeminare'] ?? null,
                    'jednotka' => $row['jednotkaSeminare'] ?? null,
                ],
                'celkova_narocnost' => $row['casovaNarocnost'] ?? null,
            ],
            'exam' => [
                'typ' => $row['typZkousky'] ?? null,
                'forma' => $row['formaZkousky'] ?? null,
                'zapocet_pred_zkouskou' => $this->fromStagBool($row['maZapocetPredZk'] ?? null),
            ],
            // The *SPodily variants (name + workload percentage) are dropped as
            // redundant with the plain name list plus the matching *UcitIdno ids.
            'people' => [
                'garanti' => $this->splitQuotedList($row['garanti'] ?? null),
                'garanti_ucit_idno' => $this->splitIds($row['garantiUcitIdno'] ?? null),
                'prednasejici' => $this->splitQuotedList($row['prednasejici'] ?? null),
                'prednasejici_ucit_idno' => $this->splitIds($row['prednasejiciUcitIdno'] ?? null),
                'cvicici' => $this->splitQuotedList($row['cvicici'] ?? null),
                'cvicici_ucit_idno' => $this->splitIds($row['cviciciUcitIdno'] ?? null),
                'seminarici' => $this->splitQuotedList($row['seminarici'] ?? null),
                'seminarici_ucit_idno' => $this->splitIds($row['seminariciUcitIdno'] ?? null),
                'examinatori' => $this->splitQuotedList($row['examinatori'] ?? null),
                'examinatori_ucit_idno' => $this->splitIds($row['examinatoriUcitIdno'] ?? null),
                'schvalujici_uznani' => $this->splitQuotedList($row['schvalujiciUznani'] ?? null),
                'schvalujici_uznani_ucit_idno' => $this->splitIds($row['schvalujiciUznaniUcitIdno'] ?? null),
            ],
            'content' => [
                'anotace' => $row['anotace'] ?? null,
                'prehled_latky' => $row['prehledLatky'] ?? null,
                'pozadavky' => $row['pozadavky'] ?? null,
                'predpoklady' => $row['predpoklady'] ?? null,
                'ziskane_zpusobilosti' => $row['ziskaneZpusobilosti'] ?? null,
                'metody_vyucovaci' => $row['metodyVyucovaci'] ?? null,
                'metody_hodnotici' => $row['metodyHodnotici'] ?? null,
                'literatura' => $this->splitQuotedList($row['literatura'] ?? null),
                'studijni_opory' => $row['studijniOpory'] ?? null,
            ],
            'relations' => [
                'podminujici' => $this->splitCodes($row['podminujiciPredmety'] ?? null),
                'vylucujici' => $this->splitCodes($row['vylucujiciPredmety'] ?? null),
                'podminuje' => $this->splitCodes($row['podminujePredmety'] ?? null),
                'nahrazuje' => $this->splitCodes($row['nahrazPredmety'] ?? null),
            ],
            'ects' => [
                'zobrazit' => $this->fromStagBool($row['ectsZobrazit'] ?? null),
                'akreditace' => $this->fromStagBool($row['ectsAkreditace'] ?? null),
                'nabizet_u_prijezdu' => $this->fromStagBool($row['ectsNabizetUPrijezdu'] ?? null),
            ],
            'misc' => [
                'poznamka' => $row['poznamka'] ?? null,
                'poznamka_verejna' => $row['poznamkaVerejna'] ?? null,
                'url' => $row['predmetUrl'] ?? null,
                'skupina_akreditace' => $row['skupinaAkreditace'] ?? null,
                'zarazen_prezencni' => $this->fromStagBool($row['zarazenDoPrezencnihoStudia'] ?? null),
                'zarazen_kombinovane' => $this->fromStagBool($row['zarazenDoKombinovanehoStudia'] ?? null),
                'praxe_pocet_dnu' => $row['praxePocetDnu'] ?? null,
                'automaticky_uznavat_zpp_zk' => $this->fromStagBool($row['automatickyUznavatZppZk'] ?? null),
                'hod_za_sem_komb_forma' => $row['hodZaSemKombForma'] ?? null,
            ],
        ];
    }

    /**
     * STAG uses both 'A'/'N' and 'ANO'/'NE' as its yes/no vocabulary,
     * sometimes on the same endpoint. Unrecognised values map to null
     * rather than guessing.
     */
    private function fromStagBool(?string $value): ?bool
    {
        return match ($value !== null ? mb_strtoupper($value) : null) {
            'A', 'ANO' => true,
            'N', 'NE' => false,
            default => null,
        };
    }

    /**
     * Splits STAG's `'Name One', 'Name Two, Jr.'`-style lists, where entries
     * are individually quoted because names and literature citations can
     * contain commas of their own. A naive explode(',', ...) would break
     * those entries apart.
     *
     * @return list<string>
     */
    private function splitQuotedList(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $entry) => trim(trim($entry), "'"),
            preg_split("/'\s*,\s*'/u", trim($value)) ?: [],
        ), fn (string $entry) => $entry !== ''));
    }

    /**
     * Splits STAG's plain comma-separated subject-code lists, which don't
     * carry the quoting that names and citations need.
     *
     * @return list<string>
     */
    private function splitCodes(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $entry) => $entry !== ''));
    }

    /**
     * @return list<int>
     */
    private function splitIds(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $entry) => (int) trim($entry),
            explode(',', $value),
        ), fn (int $entry) => $entry !== 0));
    }
}

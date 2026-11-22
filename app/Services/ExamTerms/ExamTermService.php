<?php

namespace App\Services\ExamTerms;

use App\Contracts\StagClient;
use App\Exceptions\StagException;

/**
 * Fetches exam terms from the read half of STAG's `terminy` namespace.
 *
 * Only `getTerminyZkousek` and `getTerminyProStudenta` are called here. The
 * namespace's writes are plain GETs too (`zapisStudentaNaTermin` and friends),
 * so a request to one of them registers or unregisters for real. Those live
 * in {@see ExamRegistrar} and nowhere else.
 *
 * Room bookings (`platnost` B) are filtered out on every path. Departments
 * store state exam days, summer schools and conferences as terms, and they
 * outnumber real terms several to one.
 */
final readonly class ExamTermService
{
    public function __construct(private StagClient $stag) {}

    /**
     * Terms for a subject, a department or a teacher.
     *
     * `rok` defaults to the current academic year upstream when omitted.
     *
     * @return list<ExamTerm>
     *
     * @throws StagException
     */
    public function search(?string $katedra, ?string $zkratka, ?int $teacherId, ?string $rok, ?string $semestr): array
    {
        $rows = $this->fetchTerms(array_filter([
            'katedra' => $katedra,
            'zkratka' => $zkratka,
            'ucitIdno' => $teacherId,
            'rok' => $rok,
            'semestr' => $semestr,
        ], fn (mixed $value) => $value !== null));

        return $this->toTerms($rows);
    }

    /**
     * One term by id, or null when STAG has no such term or it is a room booking.
     *
     * @throws StagException
     */
    public function find(int $termId): ?ExamTerm
    {
        return $this->toTerms($this->fetchTerms(['termIdno' => $termId]))[0] ?? null;
    }

    /**
     * One term with the student's state attached. Unlike the search path,
     * a failed state lookup is not swallowed here: a change is never offered
     * on a term whose state could not be read.
     *
     * @throws StagException
     */
    public function findForStudent(int $termId, string $osCislo): ?ExamTerm
    {
        $term = $this->find($termId);

        return $term === null ? null : $this->withRegistrations([$term], $osCislo, $term->rok())[0];
    }

    /**
     * The student's own terms: everything `getTerminyProStudenta` offers them,
     * plus every term `getTerminyZkousek?osCislo=` says they are registered on.
     * The first has the full registration state but only covers the current
     * year; the second reaches past years, so a `rok` in the past still answers.
     *
     * @return list<ExamTerm>
     *
     * @throws StagException
     */
    public function forStudent(string $osCislo, ?string $rok): array
    {
        $studentRows = $this->fetchStudentRows($osCislo);
        $registeredRows = $this->fetchTerms(array_filter(['osCislo' => $osCislo, 'rok' => $rok]));

        $terms = [];

        foreach ($registeredRows as $row) {
            $terms[(int) $row['termIdno']] = ExamTerm::fromRow($row, new Registration(registered: true));
        }

        foreach ($studentRows as $row) {
            if ($rok !== null && ($row['rok'] ?? null) !== $rok) {
                continue;
            }

            $terms[(int) $row['termIdno']] = ExamTerm::fromRow($row, Registration::fromStudentRow($row));
        }

        return array_values(array_filter($terms, fn (ExamTerm $term) => ! $term->isRoomBooking()));
    }

    /**
     * Attaches the student's registration state to terms fetched by search().
     * A term in neither student source is one they are not registered on.
     *
     * @param  list<ExamTerm>  $terms
     * @return list<ExamTerm>
     *
     * @throws StagException
     */
    public function withRegistrations(array $terms, string $osCislo, ?string $rok): array
    {
        if ($terms === []) {
            return [];
        }

        $studentRows = [];
        foreach ($this->fetchStudentRows($osCislo) as $row) {
            $studentRows[(int) $row['termIdno']] = $row;
        }

        $registeredIds = [];
        foreach ($this->fetchTerms(array_filter(['osCislo' => $osCislo, 'rok' => $rok])) as $row) {
            $registeredIds[(int) $row['termIdno']] = true;
        }

        return array_map(fn (ExamTerm $term) => $term->withRegistration(match (true) {
            isset($studentRows[$term->id]) => Registration::fromStudentRow($studentRows[$term->id]),
            isset($registeredIds[$term->id]) => new Registration(registered: true),
            default => new Registration(registered: false),
        }), $terms);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     *
     * @throws StagException
     */
    private function fetchTerms(array $filters): array
    {
        return $this->stag->get('terminy/getTerminyZkousek', [
            ...$filters,
            // Only the literal "true" parses as true upstream, so "false" is sent verbatim.
            'zobrazitBlokovane' => 'false',
        ])['termin'] ?? [];
    }

    /**
     * STAG refuses this unless `stagUser` and `osCislo` name the same student.
     *
     * @return list<array<string, mixed>>
     *
     * @throws StagException
     */
    private function fetchStudentRows(string $osCislo): array
    {
        return $this->stag->get('terminy/getTerminyProStudenta', [
            'osCislo' => $osCislo,
            'stagUser' => $osCislo,
        ])['termin'] ?? [];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<ExamTerm>
     */
    private function toTerms(array $rows): array
    {
        return array_values(array_filter(
            array_map(fn (array $row) => ExamTerm::fromRow($row), $rows),
            fn (ExamTerm $term) => ! $term->isRoomBooking(),
        ));
    }
}

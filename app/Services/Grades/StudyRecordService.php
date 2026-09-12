<?php

namespace App\Services\Grades;

use App\Clients\StagClient;
use App\Exceptions\StagException;

/**
 * Assembles a student's {@see StudyRecord} from the three STAG endpoints it
 * takes to describe one, none of which is sufficient alone:
 *
 * - `znamky/getZnamkyByStudent` has the grades, attempts, dates and examiners,
 *   but identifies a subject only as "KIV/DB1" with no name and no credits.
 * - `student/getStudentPredmetyAbsolvoval` has the names and credits.
 * - `znamky/typyHodnoceni` has the scales that say what a grade means and how
 *   it weighs into an average.
 */
final readonly class StudyRecordService
{
    public function __construct(private StagClient $stag) {}

    /**
     * The student's complete history, never a slice: the retake and average
     * rules in {@see StudyRecord} need every enrolment in hand, so narrow with
     * {@see StudyRecord::filter()} afterwards rather than here.
     *
     * @throws StagException
     */
    public function getRecordForStudent(string $osCislo): StudyRecord
    {
        // `student_na_predmetu` is a wrapper key containing the grades array.
        $rows = $this->stag->get('znamky/getZnamkyByStudent', ['osCislo' => $osCislo])['student_na_predmetu'] ?? [];

        // No rows return an empty study record.
        if ($rows === []) {
            return StudyRecord::build(rows: [], subjectsCatalogue: [], scales: GradeScales::fromPayload([]));
        }

        return StudyRecord::build($rows, $this->getSubjectsCatalogue(), $this->getGradingScalesFromStag());
    }

    /**
     * Subject names and credit values, keyed by enrolment.
     *
     * getStudentPredmetyAbsolvoval takes no osCislo — its sole documented
     * parameter is stagUser — so it always describes the ticket holder. When a
     * caller asks about some other osCislo the keys simply will not match and
     * names stay null, rather than being wrongly attached to another student's
     * enrolments.
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws StagException
     */
    private function getSubjectsCatalogue(): array
    {
        // `predmetAbsolvoval` is a wrapper key containing the subjects array.
        $rows = $this->stag->get('student/getStudentPredmetyAbsolvoval')['predmetAbsolvoval'] ?? [];

        $catalogue = [];

        foreach ($rows as $row) {
            $key = implode('|', [$row['rok'] ?? '', $row['semestr'] ?? '', $row['katedra'] ?? '', $row['zkratka'] ?? '']);
            $catalogue[$key] = $row;
        }

        return $catalogue;
    }

    /**
     * TODO: cache this with a long TTL, same shape as the TO-DO in GetBudovyTool.
     * It answers anonymously, is a couple of kilobytes, and changes roughly
     * never.
     *
     * @throws StagException
     */
    private function getGradingScalesFromStag(): GradeScales
    {
        return GradeScales::fromPayload($this->stag->get('znamky/typyHodnoceni'));
    }
}

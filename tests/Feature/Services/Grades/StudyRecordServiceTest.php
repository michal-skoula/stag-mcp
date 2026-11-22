<?php

use App\Clients\StagHttpClient;
use App\Contracts\StagClient;
use App\Exceptions\StagException;
use App\Models\User;
use App\Services\Grades\StudyRecordService;
use App\Services\Stag\ReferenceData;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\GradesFixtures;

/**
 * Needs Http::fake() and a real User (for StagClient), unlike the pure-PHP
 * tests for GradeScales/Subject/StudyRecord — but calls the service directly,
 * never through StagMcpServer/GetZnamkyTool, so it isolates the service's own
 * boundary (three STAG calls in, one StudyRecord out) instead of exercising
 * it only as a byproduct of a full tool call.
 */
function studyRecordService(): StudyRecordService
{
    return new StudyRecordService(new StagHttpClient(User::factory()->withStagToken()->create()));
}

it('assembles a StudyRecord from the three STAG endpoints', function () {
    GradesFixtures::fake([GradesFixtures::znamka()], [GradesFixtures::absolvoval()]);

    $record = studyRecordService()->getRecordForStudent('A25B0093P');

    expect($record->count())->toBe(1);

    $subject = $record->subjects()[0]->toArray();

    expect($subject)->toMatchArray(['nazev' => 'Databázové systémy 1', 'kredity' => 6]);
});

it('forwards osCislo to getZnamkyByStudent', function () {
    GradesFixtures::fake([GradesFixtures::znamka()], [GradesFixtures::absolvoval()]);

    studyRecordService()->getRecordForStudent('A25B0093P');

    Http::assertSent(fn ($request) => ! str_contains($request->url(), 'getZnamkyByStudent')
        || str_contains($request->url(), 'osCislo=A25B0093P'));
});

it('returns an empty record without calling the other two endpoints when there are no grades', function () {
    GradesFixtures::fake([], []);

    $record = studyRecordService()->getRecordForStudent('A25B0093P');

    expect($record->isEmpty())->toBeTrue();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'getStudentPredmetyAbsolvoval'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'typyHodnoceni'));
});

it('lets a STAG failure surface as a StagException', function () {
    Http::fake([
        GradesFixtures::STAG_USER_LIST_URL => Http::response(GradesFixtures::userList()),
        GradesFixtures::BY_STUDENT_URL => Http::response(status: 500),
    ]);

    expect(fn () => studyRecordService()->getRecordForStudent('A25B0093P'))
        ->toThrow(StagException::class);
});

it('fetches the grading scales once and reuses them for the next record', function () {
    GradesFixtures::fake([GradesFixtures::znamka()], [GradesFixtures::absolvoval()]);
    $service = studyRecordService();

    $service->getRecordForStudent('A25B0093P');
    $service->getRecordForStudent('A25B0093P');

    expect(collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'typyHodnoceni')))->toHaveCount(1);
});

it('builds the record from the old grading scales when STAG fails after expiry', function () {
    Http::fake([
        GradesFixtures::STAG_USER_LIST_URL => Http::response(GradesFixtures::userList()),
        GradesFixtures::BY_STUDENT_URL => Http::response(['student_na_predmetu' => [GradesFixtures::znamka()]]),
        GradesFixtures::ABSOLVOVAL_URL => Http::response(['predmetAbsolvoval' => [GradesFixtures::absolvoval()]]),
        GradesFixtures::TYPY_HODNOCENI_URL => Http::sequence()
            ->push(GradesFixtures::typyHodnoceni())
            ->push('Service Unavailable', 503),
    ]);
    $service = studyRecordService();

    $service->getRecordForStudent('A25B0093P');
    $this->travel(ReferenceData::GRADING_SCALES_TTL_SECONDS + 1)->seconds();

    expect($service->getRecordForStudent('A25B0093P')->count())->toBe(1);
});

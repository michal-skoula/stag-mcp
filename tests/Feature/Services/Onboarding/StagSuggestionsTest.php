<?php

use App\Clients\StagHttpClient;
use App\Exceptions\StagException;
use App\Models\User;
use App\Services\Onboarding\StagSuggestions;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\OnboardingFixtures as Stag;

function suggestionsFor(?User $user = null): array
{
    return (new StagSuggestions(new StagHttpClient($user ?? User::factory()->withStagToken()->create())))->build();
}

it('suggests an answer for every question a student account can answer', function () {
    Stag::fakeStudent();

    expect(suggestionsFor())->toBe([
        'roles' => ['value' => ['ST'], 'guessed' => false],
        'faculty' => ['value' => 'FAV', 'guessed' => false],
        'programme' => ['value' => 'Softwarové inženýrství', 'guessed' => false],
        'study_year' => ['value' => 2, 'guessed' => false],
        'study_start' => ['value' => 2025, 'guessed' => false],
        'city' => ['value' => 'Plzeň', 'guessed' => true],
    ]);
});

it('asks for the plans of the study under its own student number', function () {
    Stag::fakeStudent();

    suggestionsFor();

    Http::assertSent(fn ($request) => Stag::sentTo($request, 'programy/getPlanyStudenta')
        && $request['osCislo'] === 'A25B0001P');
});

it('leaves out the study questions for a teacher-only account', function () {
    Http::fake([
        Stag::USER_LIST => Http::response(Stag::userList([Stag::teacherRole()])),
        Stag::TEACHER_INFO => Http::response(Stag::teacherInfo()),
    ]);

    $suggestions = suggestionsFor();

    expect(array_keys($suggestions))->toBe(['roles', 'faculty', 'city'])
        ->and($suggestions['roles']['value'])->toBe(['VY'])
        ->and($suggestions['faculty']['value'])->toBe('FEK');
    Http::assertNotSent(fn ($request) => Stag::sentTo($request, 'programy/getPlanyStudenta'));
});

it('lists every role an account holds once', function () {
    Http::fake([
        Stag::USER_LIST => Http::response(Stag::userList([Stag::studentRole(), Stag::teacherRole(), Stag::studentRole(['osCislo' => 'A20B0002P'])])),
        Stag::STUDENT_INFO => Http::response(Stag::studentInfo()),
        Stag::TEACHER_INFO => Http::response(Stag::teacherInfo()),
        Stag::PLANS => Http::response(['planInfo' => [Stag::plan()]]),
    ]);

    expect(suggestionsFor()['roles']['value'])->toBe(['ST', 'VY']);
});

it('prefers the study that is running over an interrupted one listed first', function () {
    Http::fake([
        Stag::USER_LIST => Http::response(Stag::userList([
            Stag::studentRole(['osCislo' => 'OLD1P']),
            Stag::studentRole(['osCislo' => 'NEW2P']),
        ])),
        Stag::STUDENT_INFO.'osCislo=OLD1P*' => Http::response(Stag::studentInfo(['osCislo' => 'OLD1P', 'stav' => 'P', 'nazevSp' => 'Old programme'])),
        Stag::STUDENT_INFO.'osCislo=NEW2P*' => Http::response(Stag::studentInfo(['osCislo' => 'NEW2P', 'nazevSp' => 'Current programme'])),
        Stag::PLANS => Http::response(['planInfo' => [Stag::plan()]]),
    ]);

    expect(suggestionsFor()['programme']['value'])->toBe('Current programme');
});

it('takes the study start from the plans of the enrolled specialisation, earliest version first', function () {
    Stag::fakeStudent([
        Stag::plan(['oborIdno' => 9999, 'verze' => '2019']),
        Stag::plan(['oborIdno' => 4610, 'verze' => '2025']),
        Stag::plan(['oborIdno' => 4610, 'verze' => '2024']),
    ]);

    expect(suggestionsFor()['study_start']['value'])->toBe(2024);
});

it('falls back to all plans when none matches the specialisation', function () {
    Stag::fakeStudent([Stag::plan(['oborIdno' => 1, 'verze' => '2023'])]);

    expect(suggestionsFor()['study_start']['value'])->toBe(2023);
});

it('still answers the other questions when the plans cannot be read', function () {
    Http::fake([
        Stag::USER_LIST => Http::response(Stag::userList([Stag::studentRole()])),
        Stag::STUDENT_INFO => Http::response(Stag::studentInfo()),
        Stag::PLANS => Http::response('', 403),
    ]);

    $suggestions = suggestionsFor();

    expect($suggestions)->not->toHaveKey('study_start')
        ->and($suggestions['programme']['value'])->toBe('Softwarové inženýrství');
});

it('makes no study start of an empty plan list', function () {
    Stag::fakeStudent([]);

    expect(suggestionsFor())->not->toHaveKey('study_start');
});

it('guesses no city for a faculty it does not know', function () {
    Http::fake([
        Stag::USER_LIST => Http::response(Stag::userList([Stag::teacherRole(['fakulta' => 'XYZ'])])),
        Stag::TEACHER_INFO => Http::response(Stag::teacherInfo()),
    ]);

    expect(suggestionsFor())->not->toHaveKey('city');
});

it('guesses the city only as a flagged suggestion, from the faculty', function () {
    Http::fake([
        Stag::USER_LIST => Http::response(Stag::userList([Stag::teacherRole()])),
        Stag::TEACHER_INFO => Http::response(Stag::teacherInfo()),
    ]);

    expect(suggestionsFor()['city'])->toBe(['value' => 'Cheb', 'guessed' => true]);
});

it('fails when the identity cannot be read at all', function () {
    Http::fake([Stag::USER_LIST => Http::response('', 401)]);

    suggestionsFor();
})->throws(StagException::class);

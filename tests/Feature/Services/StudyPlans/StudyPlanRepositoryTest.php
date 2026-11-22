<?php

use App\Clients\StagHttpClient;
use App\Exceptions\StagException;
use App\Models\User;
use App\Services\StudyPlans\StudyPlanRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\StudyPlanFixtures as F;

function studyPlans(): StudyPlanRepository
{
    return new StudyPlanRepository(new StagHttpClient(User::factory()->withStagToken()->create()));
}

/**
 * @return array<string, mixed>
 */
function stagProgramme(int $id, string $kod, string $fakulta, string $nazev, string $typ = 'Ostatní'): array
{
    return ['stprIdno' => $id, 'kod' => $kod, 'fakulta' => $fakulta, 'nazev' => $nazev, 'typ' => $typ];
}

it('fetches a plan once and serves it from the cache after', function () {
    F::fakeStag();

    expect(studyPlans()->plan(25230)->name())->toBe('Softwarové inženýrství')
        ->and(studyPlans()->plan(25230)->name())->toBe('Softwarové inženýrství')
        ->and(Cache::has(StudyPlanRepository::PLAN_CACHE_PREFIX.'25230'))->toBeTrue();

    Http::assertSentCount(2);
});

it('returns null for an unknown plan and does not cache it', function () {
    Http::fake([F::PLAN_URL => Http::response('', 204)]);

    expect(studyPlans()->plan(99999999))->toBeNull()
        ->and(Cache::has(StudyPlanRepository::PLAN_CACHE_PREFIX.'99999999'))->toBeFalse();
});

it('does not cache a failed plan call', function () {
    Http::fake([F::PLAN_URL => Http::response('Internal Server Error', 500)]);

    expect(fn () => studyPlans()->plan(25230))->toThrow(StagException::class)
        ->and(Cache::has(StudyPlanRepository::PLAN_CACHE_PREFIX.'25230'))->toBeFalse();
});

it('still returns the plan when getPlanInfo is refused', function () {
    F::fakeStag([F::PLAN_INFO_URL => Http::response('Forbidden', 403)]);

    $plan = studyPlans()->plan(25230);

    expect($plan->name())->toBeNull()
        ->and($plan->blocks())->toHaveCount(7);
});

it('reads the student record as XML, passing the osCislo STAG demands', function () {
    F::fakeStag();

    expect(studyPlans()->studentRecord(F::FAKE_OS_CISLO)->currentPlanId())->toBe(25230);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'vsp/getStudent')
        && $request['osCislo'] === F::FAKE_OS_CISLO
        && $request->header('Accept') === ['application/xml']);
});

it('picks the newest plan of an obor', function () {
    F::fakeStag();

    expect(studyPlans()->currentPlanForObor(4825))->toBe(25020);
});

it('lists certificate programmes, leaving out regular degrees and Mimořádné studium', function () {
    Http::fake([
        F::PROGRAMY_URL => Http::response(['programInfo' => [
            stagProgramme(1, '9999-PIT', 'FEK', 'Certifikátový program Podnikání v IT'),
            stagProgramme(2, '9999', 'FST', 'Certifikátové programy', 'Navazující'),
            stagProgramme(3, '9999', 'REK', 'Mimořádné studium'),
            stagProgramme(4, 'B0613A140015', 'FAV', 'Informatika a výpočetní technika', 'Bakalářský'),
        ]]),
        F::OBORY_URL => fn ($request) => Http::response(['oborInfo' => match ((string) $request['stprIdno']) {
            '1' => [['oborIdno' => 4825, 'nazev' => 'Podnikání v IT', 'fakulta' => 'FEK']],
            '2' => [['oborIdno' => 100, 'nazev' => 'Zabezpečování jakosti', 'fakulta' => 'FST'], ['oborIdno' => 101, 'nazev' => 'Moderní materiály', 'fakulta' => 'FST']],
            default => [['oborIdno' => 999, 'nazev' => 'Should not appear', 'fakulta' => 'REK']],
        }]),
    ]);

    $certificates = studyPlans()->certificates();

    expect(array_column($certificates, 'certificate_id'))->toBe([4825, 100, 101])
        ->and($certificates[0])->toBe([
            'certificate_id' => 4825, 'name' => 'Podnikání v IT', 'faculty' => 'FEK',
            'programme_name' => 'Certifikátový program Podnikání v IT', 'level' => 'Ostatní',
        ]);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'getOboryStudijnihoProgramu') && in_array((string) $request['stprIdno'], ['3', '4'], true));
});

it('caches the certificate list, but not an empty one', function () {
    Http::fake([F::PROGRAMY_URL => Http::response(['programInfo' => []])]);

    expect(fn () => studyPlans()->certificates())->toThrow(StagException::class, 'certificate programme list')
        ->and(Cache::has(StudyPlanRepository::CERTIFICATES_CACHE_KEY))->toBeFalse();
});

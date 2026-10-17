<?php

use App\Clients\StagHttpClient;
use App\Exceptions\StagException;
use App\Models\User;
use App\Services\Teachers\TeacherDirectory;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\UcitelFixtures;

function teacherDirectory(): TeacherDirectory
{
    return new TeacherDirectory(new StagHttpClient(User::factory()->withStagToken()->create()));
}

/**
 * Fakes STAG with a different answer per `katedra`, so whole-university and
 * department lookups can be told apart. Unlisted scopes get an empty list.
 *
 * @param  array<string, list<array<string, mixed>>>  $rowsByScope
 */
function fakeUciteleByScope(array $rowsByScope): void
{
    Http::fake([UcitelFixtures::URL => fn ($request) => Http::response(['ucitel' => $rowsByScope[$request['katedra']] ?? []])]);
}

/**
 * @param  list<array<string, mixed>>  $rows
 * @return list<int>
 */
function teacherIds(array $rows): array
{
    return array_column($rows, 'id');
}

function teacherCacheKey(string $scope): string
{
    return TeacherDirectory::TEACHER_CACHE_PREFIX.$scope;
}

/**
 * Every cache key written since Event::fake([KeyWritten::class]), sorted.
 *
 * @return list<string>
 */
function writtenCacheKeys(): array
{
    return Event::dispatched(KeyWritten::class)
        ->map(fn (array $arguments) => $arguments[0]->key)
        ->sort()
        ->values()
        ->all();
}

it('fetches the whole university when no department is given', function () {
    UcitelFixtures::fake([UcitelFixtures::ucitel()]);

    teacherDirectory()->snapshot();

    Http::assertSent(fn ($request) => $request['katedra'] === 'REK');
});

it('lets STAG narrow by department', function () {
    UcitelFixtures::fake([]);

    teacherDirectory()->snapshot('KIV');

    Http::assertSent(fn ($request) => $request['katedra'] === 'KIV');
});

it('always asks for inactive people too', function () {
    UcitelFixtures::fake([UcitelFixtures::ucitel()]);

    teacherDirectory()->snapshot();

    // Anything but the literal "true" works; "true" is STAG's default and drops them.
    Http::assertSent(fn ($request) => $request['jenAktualni'] === 'false');
});

it('trims STAG rows to the cached shape with a search key', function () {
    UcitelFixtures::fake([UcitelFixtures::ucitel()]);

    expect(teacherDirectory()->snapshot())->toBe([[
        'id' => 301402,
        'first_name' => 'Denisa',
        'last_name' => 'Šperlová',
        'titles_before' => 'Bc.',
        'titles_after' => null,
        'department' => 'KIV',
        'other_department' => 'KIV',
        'email' => 'sperld@fav.zcu.cz',
        'active' => true,
        'employee' => true,
        'key' => 'sperlova denisa',
    ]]);
});

it('maps empty strings and nulls to null and N flags to false', function () {
    UcitelFixtures::fake([UcitelFixtures::ucitel([
        'titulPred' => '',
        'katedra' => null,
        'pracovisteDalsi' => null,
        'email' => ' ',
        'platnost' => 'N',
        'zamestnanec' => 'N',
    ])]);

    expect(teacherDirectory()->snapshot()[0])->toMatchArray([
        'titles_before' => null,
        'department' => null,
        'other_department' => null,
        'email' => null,
        'active' => false,
        'employee' => false,
    ]);
});

it('returns an empty list for an unknown department', function () {
    UcitelFixtures::fake([]);

    expect(teacherDirectory()->snapshot('XYZ'))->toBe([]);
});

it('answers a second identical lookup without calling STAG', function (?string $scope) {
    // use the Fixture::fake() to retrieve data, assert the http call happened, and that data was retrieved
    // run the same query again, this time assert the cache was accessed and that data was retrieved
    // assert the first and second calls are the same
    UcitelFixtures::fake([UcitelFixtures::ucitel()]);

    $first = teacherDirectory()->snapshot($scope);
    $second = teacherDirectory()->snapshot($scope);

    Http::assertSentCount(1);
    expect(teacherIds($first))->toBe([301402])
        ->and($second)->toBe($first);
})->with([
    'whole university' => [null],
    'department' => ['KIV'],
]);

it('keeps departments and the whole university apart', function () {
    // either fake http for REK and KIV, or fake the cache for KIV and FEK
    // make two calls to snapshot, and assert they're different and that the KIV one does not contain REK's rows.
    fakeUciteleByScope([
        'REK' => [
            UcitelFixtures::ucitel(['ucitIdno' => 1, 'katedra' => 'KIV', 'pracovisteDalsi' => null]),
            UcitelFixtures::ucitel(['ucitIdno' => 2, 'katedra' => 'KKY', 'pracovisteDalsi' => null]),
        ],
        'KIV' => [
            UcitelFixtures::ucitel(['ucitIdno' => 3, 'katedra' => 'KIV', 'pracovisteDalsi' => null]),
        ],
    ]);

    $whole = teacherDirectory()->snapshot();
    $kiv = teacherDirectory()->snapshot('KIV');

    Http::assertSentCount(2);
    expect(teacherIds($whole))->toBe([1, 2])
        ->and(teacherIds($kiv))->toBe([3]);
});

it('leaves department keys alone on a whole-university fetch', function () {
    // REK rows only name their own workplace, not the tree above it, so a key split out of them would be
    // incomplete for any code with children (FAV: 179 of 1,878). Each key holds STAG's own answer for its code.
    Cache::put(teacherCacheKey('kiv'), [['id' => 99]], TeacherDirectory::TEACHER_CACHE_TTL_SECONDS);
    fakeUciteleByScope(['REK' => [UcitelFixtures::ucitel(['ucitIdno' => 1, 'pracovisteDalsi' => null])]]);

    teacherDirectory()->snapshot();

    expect(teacherIds(Cache::get(teacherCacheKey('kiv'))))->toBe([99]);
});

it('writes only the key for the scope it fetched', function (?string $scope, string $key) {
    Event::fake([KeyWritten::class]);
    fakeUciteleByScope([
        'REK' => [UcitelFixtures::ucitel(['ucitIdno' => 1, 'katedra' => 'KIV', 'pracovisteDalsi' => 'KKY,FAV'])],
        'KIV' => [UcitelFixtures::ucitel(['ucitIdno' => 1, 'katedra' => 'KIV', 'pracovisteDalsi' => 'KKY,FAV'])],
    ]);

    teacherDirectory()->snapshot($scope);

    expect(writtenCacheKeys())->toBe([teacherCacheKey($key)]);
})->with([
    'whole university' => [null, 'all'],
    'department' => ['KIV', 'kiv'],
]);

it('treats department codes case-insensitively in the cache', function () {
    UcitelFixtures::fake([UcitelFixtures::ucitel()]);

    teacherDirectory()->snapshot('KIV');
    teacherDirectory()->snapshot('kiv');

    Http::assertSentCount(1);
});

it('fetches again once the data is too old', function () {
    // create an expired cache entry for a dept
    // request data from a dept that has expired
    // assert an http call was made and that data was retrieved
    Cache::put(teacherCacheKey('kiv'), [['id' => 99]], TeacherDirectory::TEACHER_CACHE_TTL_SECONDS);
    $this->travel(TeacherDirectory::TEACHER_CACHE_TTL_SECONDS + 1)->seconds();
    fakeUciteleByScope(['KIV' => [UcitelFixtures::ucitel(['ucitIdno' => 1])]]);

    $kiv = teacherDirectory()->snapshot('KIV');

    Http::assertSentCount(1);
    expect(teacherIds($kiv))->toBe([1]);
});

it('does not cache a failed STAG call', function () {
    Http::fake([UcitelFixtures::URL => Http::sequence()
        ->push('Service Unavailable', 503)
        ->push(['ucitel' => [UcitelFixtures::ucitel()]]),
    ]);

    expect(fn () => teacherDirectory()->snapshot())->toThrow(StagException::class);

    expect(teacherIds(teacherDirectory()->snapshot()))->toBe([301402]);
    Http::assertSentCount(2);
});

it('treats an empty whole-university answer as a STAG failure and does not cache it', function () {
    Http::fake([UcitelFixtures::URL => Http::sequence()
        ->push(['ucitel' => []])
        ->push(['ucitel' => [UcitelFixtures::ucitel()]]),
    ]);

    expect(fn () => teacherDirectory()->snapshot())->toThrow(StagException::class, 'empty teacher directory');

    expect(teacherIds(teacherDirectory()->snapshot()))->toBe([301402]);
    Http::assertSentCount(2);
});

it('caches an empty department answer, since unknown codes legitimately return nothing', function () {
    UcitelFixtures::fake([]);

    teacherDirectory()->snapshot('XYZ');
    teacherDirectory()->snapshot('XYZ');

    Http::assertSentCount(1);
});

it('falls back to old data if stag is down and cache key is expired')
    ->todo('read todo #1 on the class, implement when this is added');

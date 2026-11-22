<?php

use App\Clients\StagHttpClient;
use App\Exceptions\StagException;
use App\Models\User;
use App\Services\Stag\ReferenceDataCache;
use App\Services\Teachers\TeacherDirectory;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Carbon;
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
 * Stores an entry the way {@see ReferenceDataCache} does, retrieved some time ago.
 *
 * @param  list<array<string, mixed>>  $rows
 */
function seedTeacherSnapshot(string $scope, array $rows, int $secondsAgo = 0): void
{
    $retrievedAt = now()->subSeconds($secondsAgo)->getTimestamp();
    $key = teacherCacheKey($scope);

    Cache::forever($key, ['retrieved_at' => $retrievedAt, 'data' => $rows]);
    Cache::forever(TeacherDirectory::SCOPE_INDEX_KEY, [...(Cache::get(TeacherDirectory::SCOPE_INDEX_KEY) ?? []), $key => $retrievedAt]);
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

    expect(teacherDirectory()->snapshot()->data)->toBe([[
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

    expect(teacherDirectory()->snapshot()->data[0])->toMatchArray([
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

    expect(teacherDirectory()->snapshot('XYZ')->data)->toBe([]);
});

it('answers a second identical lookup without calling STAG', function (?string $scope) {
    // use the Fixture::fake() to retrieve data, assert the http call happened, and that data was retrieved
    // run the same query again, this time assert the cache was accessed and that data was retrieved
    // assert the first and second calls are the same
    UcitelFixtures::fake([UcitelFixtures::ucitel()]);

    $first = teacherDirectory()->snapshot($scope)->data;
    $second = teacherDirectory()->snapshot($scope)->data;

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

    $whole = teacherDirectory()->snapshot()->data;
    $kiv = teacherDirectory()->snapshot('KIV')->data;

    Http::assertSentCount(2);
    expect(teacherIds($whole))->toBe([1, 2])
        ->and(teacherIds($kiv))->toBe([3]);
});

it('leaves department keys alone on a whole-university fetch', function () {
    // REK rows only name their own workplace, not the tree above it, so a key split out of them would be
    // incomplete for any code with children (FAV: 179 of 1,878). Each key holds STAG's own answer for its code.
    seedTeacherSnapshot('kiv', [['id' => 99]]);
    fakeUciteleByScope(['REK' => [UcitelFixtures::ucitel(['ucitIdno' => 1, 'pracovisteDalsi' => null])]]);

    teacherDirectory()->snapshot();

    expect(teacherIds(Cache::get(teacherCacheKey('kiv'))['data']))->toBe([99]);
});

it('writes only the key for the scope it fetched, plus the scope index', function (?string $scope, string $key) {
    Event::fake([KeyWritten::class]);
    fakeUciteleByScope([
        'REK' => [UcitelFixtures::ucitel(['ucitIdno' => 1, 'katedra' => 'KIV', 'pracovisteDalsi' => 'KKY,FAV'])],
        'KIV' => [UcitelFixtures::ucitel(['ucitIdno' => 1, 'katedra' => 'KIV', 'pracovisteDalsi' => 'KKY,FAV'])],
    ]);

    teacherDirectory()->snapshot($scope);

    expect(writtenCacheKeys())->toBe([teacherCacheKey($key), TeacherDirectory::SCOPE_INDEX_KEY]);
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
    seedTeacherSnapshot('kiv', [['id' => 99]], TeacherDirectory::TEACHER_CACHE_TTL_SECONDS + 1);
    fakeUciteleByScope(['KIV' => [UcitelFixtures::ucitel(['ucitIdno' => 1])]]);

    $kiv = teacherDirectory()->snapshot('KIV')->data;

    Http::assertSentCount(1);
    expect(teacherIds($kiv))->toBe([1]);
});

it('does not cache a failed STAG call', function () {
    Http::fake([UcitelFixtures::URL => Http::sequence()
        ->push('Service Unavailable', 503)
        ->push(['ucitel' => [UcitelFixtures::ucitel()]]),
    ]);

    expect(fn () => teacherDirectory()->snapshot())->toThrow(StagException::class);

    expect(teacherIds(teacherDirectory()->snapshot()->data))->toBe([301402]);
    Http::assertSentCount(2);
});

it('treats an empty whole-university answer as a STAG failure and does not cache it', function () {
    Http::fake([UcitelFixtures::URL => Http::sequence()
        ->push(['ucitel' => []])
        ->push(['ucitel' => [UcitelFixtures::ucitel()]]),
    ]);

    expect(fn () => teacherDirectory()->snapshot())->toThrow(StagException::class, 'empty teacher directory');

    expect(teacherIds(teacherDirectory()->snapshot()->data))->toBe([301402]);
    Http::assertSentCount(2);
});

it('caches an empty department answer, since unknown codes legitimately return nothing', function () {
    UcitelFixtures::fake([]);

    teacherDirectory()->snapshot('XYZ');
    teacherDirectory()->snapshot('XYZ');

    Http::assertSentCount(1);
});

it('falls back to the old snapshot and flags it when STAG fails after expiry', function () {
    $retrievedAt = now()->subSeconds(TeacherDirectory::TEACHER_CACHE_TTL_SECONDS + 60);
    seedTeacherSnapshot('kiv', [['id' => 99]], TeacherDirectory::TEACHER_CACHE_TTL_SECONDS + 60);
    Http::fake([UcitelFixtures::URL => Http::response('Service Unavailable', 503)]);

    $snapshot = teacherDirectory()->snapshot('KIV');

    expect(teacherIds($snapshot->data))->toBe([99])
        ->and($snapshot->outdated)->toBeTrue()
        ->and($snapshot->retrievedAt->getTimestamp())->toBe($retrievedAt->getTimestamp());
});

it('serves an old whole-university snapshot when STAG answers with an empty list', function () {
    seedTeacherSnapshot('all', [['id' => 99]], TeacherDirectory::TEACHER_CACHE_TTL_SECONDS + 60);
    UcitelFixtures::fake([]);

    $snapshot = teacherDirectory()->snapshot();

    expect(teacherIds($snapshot->data))->toBe([99])
        ->and($snapshot->outdated)->toBeTrue();
});

it('still throws when STAG fails and there is no old snapshot', function () {
    Http::fake([UcitelFixtures::URL => Http::response('Service Unavailable', 503)]);

    expect(fn () => teacherDirectory()->snapshot('KIV'))->toThrow(StagException::class);
});

it('does not flag a fresh or a re-fetched snapshot as outdated', function () {
    seedTeacherSnapshot('kiv', [['id' => 99]], 60);
    UcitelFixtures::fake([UcitelFixtures::ucitel(['ucitIdno' => 1])]);

    $fresh = teacherDirectory()->snapshot('KIV');
    $this->travel(TeacherDirectory::TEACHER_CACHE_TTL_SECONDS)->seconds();
    $refetched = teacherDirectory()->snapshot('KIV');

    expect($fresh->outdated)->toBeFalse()
        ->and($refetched->outdated)->toBeFalse()
        ->and(teacherIds($refetched->data))->toBe([1]);
});

it('keeps the old snapshot in the cache after a failed refresh', function () {
    seedTeacherSnapshot('kiv', [['id' => 99]], TeacherDirectory::TEACHER_CACHE_TTL_SECONDS + 60);
    Http::fake([UcitelFixtures::URL => Http::response('Service Unavailable', 503)]);

    teacherDirectory()->snapshot('KIV');

    expect(teacherIds(Cache::get(teacherCacheKey('kiv'))['data']))->toBe([99]);
});

it('stops asking STAG for a few minutes after a failed refresh', function () {
    seedTeacherSnapshot('kiv', [['id' => 99]], TeacherDirectory::TEACHER_CACHE_TTL_SECONDS + 60);
    Http::fake([UcitelFixtures::URL => Http::sequence()
        ->push('Service Unavailable', 503)
        ->push(['ucitel' => [UcitelFixtures::ucitel(['ucitIdno' => 1])]]),
    ]);

    teacherDirectory()->snapshot('KIV');
    $second = teacherDirectory()->snapshot('KIV');

    Http::assertSentCount(1);
    expect($second->outdated)->toBeTrue();

    $this->travel(ReferenceDataCache::RETRY_COOLDOWN_SECONDS + 1)->seconds();

    expect(teacherDirectory()->snapshot('KIV')->outdated)->toBeFalse();
});

it('replaces an old snapshot once STAG answers again', function () {
    seedTeacherSnapshot('kiv', [['id' => 99]], TeacherDirectory::TEACHER_CACHE_TTL_SECONDS + 60);
    UcitelFixtures::fake([UcitelFixtures::ucitel(['ucitIdno' => 1])]);

    teacherDirectory()->snapshot('KIV');

    expect(teacherIds(teacherDirectory()->snapshot('KIV')->data))->toBe([1]);
    Http::assertSentCount(1);
});

it('reads a snapshot written in the old plain-list format as missing', function () {
    Cache::put(teacherCacheKey('kiv'), [['id' => 99]], 600);
    UcitelFixtures::fake([UcitelFixtures::ucitel(['ucitIdno' => 1])]);

    expect(teacherIds(teacherDirectory()->snapshot('KIV')->data))->toBe([1]);
});

it('carries the snapshot freshness through search', function () {
    seedTeacherSnapshot('all', [['id' => 1, 'key' => 'sperlova denisa', 'last_name' => 'Šperlová', 'active' => true, 'employee' => true]], TeacherDirectory::TEACHER_CACHE_TTL_SECONDS + 60);
    Http::fake([UcitelFixtures::URL => Http::response('Service Unavailable', 503)]);

    $result = teacherDirectory()->search('sperlova');

    expect($result->outdated)->toBeTrue()
        ->and(teacherIds($result->data))->toBe([1]);
});

describe('scheduled refresh', function () {
    it('is due when nothing has been cached yet', function () {
        expect(teacherDirectory()->isRefreshDue())->toBeTrue();
    });

    it('is not due while the whole-university snapshot is recent', function () {
        $this->travelTo(now()->setDate(2026, 11, 20));
        seedTeacherSnapshot('all', [['id' => 1]], TeacherDirectory::REFRESH_AFTER_SECONDS - 3600);

        expect(teacherDirectory()->isRefreshDue())->toBeFalse();
    });

    it('is due once the snapshot reaches the refresh age', function () {
        $this->travelTo(now()->setDate(2026, 11, 20));
        seedTeacherSnapshot('all', [['id' => 1]], TeacherDirectory::REFRESH_AFTER_SECONDS);

        expect(teacherDirectory()->isRefreshDue())->toBeTrue();
    });

    it('is due when only a department snapshot has aged out', function () {
        $this->travelTo(now()->setDate(2026, 11, 20));
        seedTeacherSnapshot('all', [['id' => 1]], 60);
        seedTeacherSnapshot('kiv', [['id' => 2]], TeacherDirectory::REFRESH_AFTER_SECONDS + 60);

        expect(teacherDirectory()->isRefreshDue())->toBeTrue();
    });

    it('refreshes daily between mid-September and mid-October', function (string $date, bool $due) {
        $this->travelTo(Carbon::parse($date));
        seedTeacherSnapshot('all', [['id' => 1]], TeacherDirectory::SEASON_REFRESH_AFTER_SECONDS + 60);

        expect(teacherDirectory()->isRefreshDue())->toBe($due);
    })->with([
        'before the window' => ['2026-09-10 12:00', false],
        'first day' => ['2026-09-15 12:00', true],
        'last day' => ['2026-10-15 12:00', true],
        'after the window' => ['2026-10-16 12:00', false],
    ]);

    it('refreshes REK and every indexed department in one run', function () {
        seedTeacherSnapshot('all', [['id' => 1]], 100);
        seedTeacherSnapshot('kiv', [['id' => 2]], 100);
        seedTeacherSnapshot('fav', [['id' => 3]], 100);
        fakeUciteleByScope([
            'REK' => [UcitelFixtures::ucitel(['ucitIdno' => 10])],
            'KIV' => [UcitelFixtures::ucitel(['ucitIdno' => 11])],
            'FAV' => [UcitelFixtures::ucitel(['ucitIdno' => 12])],
        ]);

        $results = teacherDirectory()->refreshAll();

        expect($results)->toBe(['REK' => true, 'KIV' => true, 'FAV' => true])
            ->and(teacherIds(Cache::get(teacherCacheKey('all'))['data']))->toBe([10])
            ->and(teacherIds(Cache::get(teacherCacheKey('kiv'))['data']))->toBe([11])
            ->and(teacherIds(Cache::get(teacherCacheKey('fav'))['data']))->toBe([12]);

        $timestamps = array_unique(array_column([Cache::get(teacherCacheKey('all')), Cache::get(teacherCacheKey('kiv')), Cache::get(teacherCacheKey('fav'))], 'retrieved_at'));
        expect($timestamps)->toHaveCount(1);
    });

    it('refreshes REK even when no department was ever cached', function () {
        fakeUciteleByScope(['REK' => [UcitelFixtures::ucitel()]]);

        expect(teacherDirectory()->refreshAll())->toBe(['REK' => true]);
        Http::assertSent(fn ($request) => $request['katedra'] === 'REK');
    });

    it('keeps the old snapshot of a department whose refresh failed and refreshes the rest', function () {
        seedTeacherSnapshot('all', [['id' => 1]], 100);
        seedTeacherSnapshot('kiv', [['id' => 2]], 100);
        seedTeacherSnapshot('fav', [['id' => 3]], 100);
        Http::fake([UcitelFixtures::URL => fn ($request) => $request['katedra'] === 'KIV'
            ? Http::response('Service Unavailable', 503)
            : Http::response(['ucitel' => [UcitelFixtures::ucitel(['ucitIdno' => 10])]])]);

        expect(teacherDirectory()->refreshAll())->toBe(['REK' => true, 'KIV' => false, 'FAV' => true])
            ->and(teacherIds(Cache::get(teacherCacheKey('kiv'))['data']))->toBe([2])
            ->and(teacherIds(Cache::get(teacherCacheKey('fav'))['data']))->toBe([10]);
    });

    it('keeps everything and skips the departments when REK fails', function () {
        seedTeacherSnapshot('all', [['id' => 1]], 100);
        seedTeacherSnapshot('kiv', [['id' => 2]], 100);
        Http::fake([UcitelFixtures::URL => Http::response('Service Unavailable', 503)]);

        expect(teacherDirectory()->refreshAll())->toBe(['REK' => false]);
        Http::assertSentCount(1);
        expect(teacherIds(Cache::get(teacherCacheKey('all'))['data']))->toBe([1])
            ->and(teacherIds(Cache::get(teacherCacheKey('kiv'))['data']))->toBe([2]);
    });

    it('treats an empty REK answer as a failed refresh', function () {
        seedTeacherSnapshot('all', [['id' => 1]], 100);
        UcitelFixtures::fake([]);

        expect(teacherDirectory()->refreshAll())->toBe(['REK' => false])
            ->and(teacherIds(Cache::get(teacherCacheKey('all'))['data']))->toBe([1]);
    });

    it('does not index a department that came back empty, so a typo costs no standing call', function () {
        UcitelFixtures::fake([]);

        teacherDirectory()->snapshot('XYZ');

        expect(Cache::get(TeacherDirectory::SCOPE_INDEX_KEY))->toBeNull();
    });

    it('indexes a department once it has people, and each scope keeps its own timestamp', function () {
        fakeUciteleByScope(['KIV' => [UcitelFixtures::ucitel()]]);

        teacherDirectory()->snapshot('KIV');

        expect(array_keys(Cache::get(TeacherDirectory::SCOPE_INDEX_KEY)))->toBe([teacherCacheKey('kiv')]);
    });
});

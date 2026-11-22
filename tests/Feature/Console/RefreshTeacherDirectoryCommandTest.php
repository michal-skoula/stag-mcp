<?php

use App\Models\User;
use App\Services\Teachers\TeacherDirectory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\UcitelFixtures;

it('is scheduled hourly', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command, 'stag:refresh-teachers'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('warms the directory using an account with a live ticket', function () {
    User::factory()->withExpiredStagToken()->create();
    User::factory()->withStagToken('live-ticket')->create();
    UcitelFixtures::fake([UcitelFixtures::ucitel()]);

    $this->artisan('stag:refresh-teachers')->assertSuccessful();

    Http::assertSent(fn ($request) => $request['katedra'] === 'REK' && $request->hasHeader('Cookie', 'WSCOOKIE=live-ticket'));
    expect(Cache::has(TeacherDirectory::TEACHER_CACHE_PREFIX.'all'))->toBeTrue();
});

it('does nothing when no account holds a live ticket', function () {
    User::factory()->withExpiredStagToken()->create();
    User::factory()->create();
    Http::fake();

    $this->artisan('stag:refresh-teachers')->assertSuccessful();

    Http::assertNothingSent();
});

it('skips the run while the snapshots are recent, unless forced', function () {
    User::factory()->withStagToken()->create();
    UcitelFixtures::fake([UcitelFixtures::ucitel()]);

    $this->artisan('stag:refresh-teachers')->assertSuccessful();
    $this->artisan('stag:refresh-teachers')->assertSuccessful();
    Http::assertSentCount(1);

    $this->artisan('stag:refresh-teachers --force')->assertSuccessful();
    Http::assertSentCount(2);
});

it('tries the next ticket when STAG refuses the first, and fails when all are refused', function () {
    User::factory()->withStagToken()->count(2)->create();
    Http::fake([UcitelFixtures::URL => Http::response('Unauthorized', 401)]);

    $this->artisan('stag:refresh-teachers')->assertFailed();

    Http::assertSentCount(2);
    expect(Cache::has(TeacherDirectory::TEACHER_CACHE_PREFIX.'all'))->toBeFalse();
});

it('fails and keeps the old snapshot of a department that could not be refreshed', function () {
    User::factory()->withStagToken()->create();
    $old = ['retrieved_at' => now()->subDays(8)->getTimestamp(), 'data' => [['id' => 99]]];
    Cache::forever(TeacherDirectory::TEACHER_CACHE_PREFIX.'all', $old);
    Cache::forever(TeacherDirectory::TEACHER_CACHE_PREFIX.'kiv', $old);
    Cache::forever(TeacherDirectory::SCOPE_INDEX_KEY, [
        TeacherDirectory::TEACHER_CACHE_PREFIX.'all' => $old['retrieved_at'],
        TeacherDirectory::TEACHER_CACHE_PREFIX.'kiv' => $old['retrieved_at'],
    ]);
    Http::fake([UcitelFixtures::URL => fn ($request) => $request['katedra'] === 'KIV'
        ? Http::response('Service Unavailable', 503)
        : Http::response(['ucitel' => [UcitelFixtures::ucitel()]])]);

    $this->artisan('stag:refresh-teachers')->assertFailed();

    expect(Cache::get(TeacherDirectory::TEACHER_CACHE_PREFIX.'kiv'))->toBe($old)
        ->and(Cache::get(TeacherDirectory::TEACHER_CACHE_PREFIX.'all')['data'][0]['id'])->toBe(301402);
});

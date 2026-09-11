<?php

use App\Mcp\Servers\StagMcpServer;
use App\Mcp\Tools\ListNotificationsTool;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

const STAG_LIST = 'stag-ws.zcu.cz/ws/services/rest2/oznameni/list*';

/**
 * One row shaped exactly as STAG returns it, Czech keys and all.
 *
 * @return array<string, mixed>
 */
function stagNotification(array $overrides = []): array
{
    return array_merge([
        'notiIdno' => 3764836,
        'dateOfInsert' => ['value' => '3.9.2026 13:54'],
        'typ' => 'ELAKUZ',
        'doruceni' => 'N',
        'url' => 'https://phix.zcu.cz/moodle/course/view.php?id=15436',
        'osobIdno' => 245836,
        'osCislo' => 'A25B0093P',
        'ucitIdno' => null,
        'stagUserName' => null,
        'predmet' => 'Nová aktivita v Moodle',
        'zprava' => 'Byl(a) jste přidán(a) k aktivitě UJP/AEP6.',
        'odeslano' => ['value' => '3.9.2026 13:54'],
        'precteno' => ['value' => '4.9.2026 16:00'],
    ], $overrides);
}

// The gate itself is covered in TraitRequiresStagLoginTest. This is the one case that
// proves this tool sits behind it, which that file cannot show: it passes either way.
// Tool::handle() is not abstract, so a tool defining its own would skip the gate silently.
it('refuses a caller with no STAG ticket', function () {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(ListNotificationsTool::class)
        ->assertHasErrors();

    Http::assertNothingSent();
});

it('asks only for unread notifications by default', function () {
    Http::fake([STAG_LIST => Http::response([])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(ListNotificationsTool::class)
        ->assertOk();

    Http::assertSent(fn ($request) => $request['jenNeprectene'] === 'true');
});

it('asks for every notification when show_read is set', function () {
    Http::fake([STAG_LIST => Http::response([])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(ListNotificationsTool::class, ['show_read' => true])
        ->assertOk();

    Http::assertSent(fn ($request) => $request['jenNeprectene'] === 'false');
});

it('converts newer_than to a midnight millisecond timestamp', function () {
    Http::fake([STAG_LIST => Http::response([])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(ListNotificationsTool::class, ['newer_than' => '2026-09-03'])
        ->assertOk();

    $expected = Carbon::parse('2026-09-03 00:00:00')->getTimestampMs();

    Http::assertSent(fn ($request) => $request['fromTimestamp'] === $expected);
});

it('reshapes the STAG payload into compact english fields', function () {
    Http::fake([STAG_LIST => Http::response([stagNotification()])]);

    $response = StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(ListNotificationsTool::class)
        ->assertOk();

    $response->assertSee('"id":3764836')
        ->assertSee('"subject":"Nová aktivita v Moodle"')
        ->assertSee('"sent_at":"2026-09-03T13:54:00')
        ->assertSee('"read_at":"2026-09-04T16:00:00')
        ->assertSee('"count":1');
});

it('reports an unread notification with a null read_at', function () {
    Http::fake([STAG_LIST => Http::response([stagNotification(['precteno' => null])])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(ListNotificationsTool::class)
        ->assertOk()
        ->assertSee('"read_at":null');
});

it('returns an empty list without erroring', function () {
    Http::fake([STAG_LIST => Http::response([])]);

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(ListNotificationsTool::class)
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"count":0');
});

it('rejects a newer_than that is not a date', function () {
    Http::fake();

    StagMcpServer::actingAs(User::factory()->withStagToken()->create())
        ->tool(ListNotificationsTool::class, ['newer_than' => 'yesterday'])
        ->assertHasErrors();

    Http::assertNothingSent();
});

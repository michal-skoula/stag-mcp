<?php

use App\Mcp\Servers\StagMcpServer;
use App\Mcp\Tools\ListCertifikatyTool;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\StudyPlanFixtures as F;

function fakeCertificates(): void
{
    Http::fake([
        F::PROGRAMY_URL => Http::response(['programInfo' => [
            ['stprIdno' => 1, 'kod' => '9999-PIT', 'fakulta' => 'FEK', 'nazev' => 'Certifikátový program Podnikání v IT', 'typ' => 'Ostatní'],
            ['stprIdno' => 2, 'kod' => '9999', 'fakulta' => 'FAV', 'nazev' => 'Certifikátové programy', 'typ' => 'Navazující'],
        ]]),
        F::OBORY_URL => fn ($request) => Http::response(['oborInfo' => (string) $request['stprIdno'] === '1'
            ? [['oborIdno' => 4825, 'nazev' => 'Podnikání v IT', 'fakulta' => 'FEK']]
            : [['oborIdno' => 4700, 'nazev' => 'Technologie pro bezpilotní létání', 'fakulta' => 'FAV']]]),
    ]);
}

it('lists certificates without a STAG ticket', function () {
    fakeCertificates();

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(ListCertifikatyTool::class)
        ->assertOk()
        ->assertSee('"total":2')
        ->assertSee('{"certificate_id":4825,"name":"Podnikání v IT","faculty":"FEK","programme_name":"Certifikátový program Podnikání v IT","level":"Ostatní"}')
        ->assertSee('"name":"Technologie pro bezpilotní létání"');
});

it('narrows to one faculty, ignoring case', function () {
    fakeCertificates();

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(ListCertifikatyTool::class, ['faculty' => ' fav '])
        ->assertOk()
        ->assertSee('"total":1')
        ->assertDontSee('Podnikání v IT');
});

it('errors when there is no authenticated user', function () {
    Http::fake();

    StagMcpServer::tool(ListCertifikatyTool::class)
        ->assertHasErrors()
        ->assertSee('must send a bearer token');

    Http::assertNothingSent();
});

it('reports a STAG failure', function () {
    Http::fake([F::PROGRAMY_URL => Http::response('Internal Server Error', 500)]);

    StagMcpServer::actingAs(User::factory()->create())
        ->tool(ListCertifikatyTool::class)
        ->assertHasErrors(['unexpected HTTP 500']);
});

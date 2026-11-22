<?php

namespace App\Mcp\Tools;

use App\Contracts\StagClient;
use App\Exceptions\StagException;
use App\Mcp\Concerns\RequiresStagLogin;
use App\Services\Stag\StagProfile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('who-am-i')]
#[Title('Who Am I')]
#[Description('Who the caller is in STAG: name, email, and one entry per role the account holds. A student role carries the study programme, year, form and state (use get-study-advisor for the referentka); a teaching role carries the department and contact details. One account can hold several roles at once, each with its own personal number, so this is how to find the os_cislo that get-znamky and get-kalendar accept. Takes no arguments - it always describes the account behind the ticket.')]
#[IsReadOnly]
class WhoAmITool extends Tool
{
    use RequiresStagLogin;

    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fhelp
    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fstudent
    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fucitel

    /**
     * The tool describes the ticket holder, so there is nothing to filter on.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $nullableString = fn () => $schema->anyOf([$schema->string()])->nullable();
        $nullableInt = fn () => $schema->anyOf([$schema->integer()])->nullable();
        $nullableBool = fn () => $schema->anyOf([$schema->boolean()])->nullable();

        return [
            'name' => $schema->object([
                'first' => $nullableString()->description('Given name.'),
                'last' => $nullableString()->description('Surname, as STAG stores it (often all caps).'),
                'title_before' => $nullableString()->description('Titles before the name, e.g. "JUDr.".'),
                'title_after' => $nullableString()->description('Titles after the name, e.g. "Ph.D.".'),
                'full' => $schema->string()->description('Titles and name joined for display.'),
            ])->description('The person behind the account. The same for every role.'),
            'email' => $nullableString()->description('Primary contact address on the account. A role may carry a different one.'),
            'roles' => $schema->array()->description('Every STAG role this account holds, most capable first as STAG orders them.')
                ->items($schema->object([
                    'role' => $nullableString()->description('Role code, e.g. "ST" (student) or "VY" (teacher).'),
                    'role_name' => $nullableString()->description("STAG's Czech name for the role."),
                    'user_name' => $nullableString()->description('Login this role is held under.'),
                    'fakulta' => $nullableString()->description('Faculty abbreviation, e.g. "FAV".'),
                    'katedra' => $nullableString()->description('Department abbreviation, set for teaching roles.'),
                    'os_cislo' => $nullableString()->description('Personal number. Set for student roles; pass it to get-znamky or get-kalendar.'),
                    'ucit_idno' => $nullableInt()->description('Teacher id. Set for teaching roles.'),
                    'email' => $nullableString()->description('Address for this role specifically.'),
                    'student' => $schema->anyOf([$schema->object([
                        'os_cislo' => $schema->string()->description('Personal number this study is recorded under.'),
                        'orion_login' => $nullableString()->description('University-wide (Orion) login.'),
                        'email' => $nullableString()->description('School address for this study.'),
                        'state' => $nullableString()->description('Whether the study is running: "studying", "interrupted" or "not studying".'),
                        'form' => $nullableString()->description('How the study is attended: "full-time", "combined" or "distance".'),
                        'type' => $nullableString()->description('Degree level: "bachelor", "follow-up master", "master" or "doctoral".'),
                        'rocnik' => $nullableString()->description('Year of study, as STAG sends it (a quoted number).'),
                        'programme' => $schema->object([
                            'name' => $nullableString()->description('Programme name. Czech only; STAG ignores lang on this endpoint.'),
                            'code' => $nullableString()->description('Accreditation code, e.g. "B0613A140037".'),
                            'faculty' => $nullableString()->description('Faculty running the programme.'),
                            'id' => $nullableString()->description("STAG's internal programme id, accepted by other STAG endpoints."),
                            'specialisation' => $nullableString()->description('Specialisation combination, e.g. "SWI23bp".'),
                            'specialisation_ids' => $nullableString()->description("STAG's internal specialisation ids."),
                        ])->description('The study programme being read.'),
                    ])])->description('Study detail, present on student roles only.')->nullable(),
                    'teacher' => $schema->anyOf([$schema->object([
                        'ucit_idno' => $schema->integer()->description('Teacher id.'),
                        'katedra' => $nullableString()->description('Home department.'),
                        'pracoviste_dalsi' => $nullableString()->description('Further workplaces.'),
                        'email' => $nullableString()->description('Work email.'),
                        'telefon' => $nullableString()->description('Work phone.'),
                        'telefon2' => $nullableString()->description('Second work phone.'),
                        'url' => $nullableString()->description('Personal page.'),
                        'zkr_budovy' => $nullableString()->description('Office building abbreviation.'),
                        'cislo_mistnosti' => $nullableString()->description('Office room number.'),
                        'platnost' => $nullableBool()->description('Whether the record is current.'),
                        'zamestnanec' => $nullableBool()->description('Whether this person is an employee.'),
                        'phd_skolitel' => $nullableBool()->description('Whether this person supervises doctoral students.'),
                    ])])->description('Teaching detail, present on teaching roles only.')->nullable(),
                ])),
        ];
    }

    /**
     * Handle the tool request.
     *
     * @throws StagException
     */
    protected function handleForStagUser(Request $request, StagClient $stag): ResponseFactory|Response
    {
        return Response::structured((new StagProfile($stag))->describe());
    }
}

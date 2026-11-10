<?php

namespace App\Mcp\Tools;

use App\Clients\StagHttpClient;
use App\Exceptions\StagException;
use App\Models\User;
use App\Services\StudyPlans\StudyPlanRepository;
use Illuminate\Container\Attributes\CurrentUser;
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

#[Name('list-certifikaty')]
#[Title('List Certifikaty')]
#[Description('Certificate programmes (certifikátové programy) a student can complete alongside their degree, such as Podnikání v IT or Technologie pro bezpilotní létání. There is no enrolment: students take the programme\'s subjects through předzápis, and most close with a final exam that is itself one of the subjects. The certificate comes as an attachment to the diploma. Pass a certificate_id to get-studijni-plan for its subjects, or to get-studijni-pokrok to see how much of it the caller has already passed.')]
#[IsReadOnly]
class ListCertifikatyTool extends Tool
{
    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fprogramy

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'faculty' => $schema->string()
                ->description('Faculty code to narrow the list, e.g. "FEK", "FST", "FAV". Case-insensitive.'),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $nullableString = fn () => $schema->anyOf([$schema->string()])->nullable();

        return [
            'total' => $schema->integer()->description('Certificates returned.'),
            'certificates' => $schema->array()->items($schema->object([
                'certificate_id' => $schema->integer()->description('Pass to get-studijni-plan or get-studijni-pokrok.'),
                'name' => $nullableString()->description('Certificate name in Czech.'),
                'faculty' => $nullableString()->description('Faculty running it.'),
                'programme_name' => $nullableString()->description('The certificate programme it belongs to; some faculties group all their certificates under one.'),
                'level' => $nullableString()->description('Study level STAG files it under, e.g. "Bakalářský", "Navazující", "Ostatní". A hint at who it is meant for, not an entry rule.'),
            ])),
        ];
    }

    /**
     * Handle the tool request. The catalogue answers anonymously.
     */
    public function handle(Request $request, #[CurrentUser('sanctum')] ?User $user = null): ResponseFactory|Response
    {
        if ($user === null) {
            return Response::error('No authenticated user. The MCP client must send a bearer token.');
        }

        $validated = $request->validate([
            'faculty' => ['nullable', 'string', 'max:10'],
        ]);

        try {
            $certificates = (new StudyPlanRepository(new StagHttpClient($user)))->certificates();
        } catch (StagException $e) {
            return Response::error($e->getMessage());
        }

        $faculty = isset($validated['faculty']) ? mb_strtoupper(trim($validated['faculty'])) : null;

        if ($faculty !== null && $faculty !== '') {
            $certificates = array_values(array_filter($certificates, fn (array $certificate) => $certificate['faculty'] === $faculty));
        }

        return Response::structured([
            'total' => count($certificates),
            'certificates' => $certificates,
        ]);
    }
}

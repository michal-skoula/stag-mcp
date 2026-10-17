<?php

namespace App\Mcp\Tools;

use App\Contracts\StagClient;
use App\Exceptions\StagException;
use App\Mcp\Concerns\PaginatesResponses;
use App\Mcp\Concerns\RequiresStagLogin;
use App\Services\Teachers\TeacherDirectory;
use App\Services\Teachers\TeacherSearch;
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

#[Name('search-ucitele')]
#[Title('Search Ucitele')]
#[Description('Finds teachers and other university staff by name, department, or both. Name matching ignores case, diacritics and word order ("sperlova", "Denisa Šperlová"). Best matches come first. Returns teacher_id, the handle other tools use for a person. People flagged active: false are still returned on purpose: STAG marks student demonstrators and doctoral assistants inactive while they run labs, so an inactive match may well be the person the user means. Results are paged; use offset to see more than the first count rows.')]
#[IsReadOnly]
class SearchUciteleTool extends Tool
{
    use PaginatesResponses;
    use RequiresStagLogin;

    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fucitel

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Any part of a first name or surname, in any order, e.g. "herout" or "Jan Novák". Titles are ignored. Required unless department is given.'),
            'department' => $schema->string()
                ->description('Department or faculty code, e.g. "KIV" or "FAV". A faculty code includes all of its departments. An unknown code returns no results rather than an error, so an empty result may mean a typo. Required unless name is given.'),
            'active_only' => $schema->boolean()
                ->description('Drop people STAG flags inactive. Off by default, because the inactive flag also covers people who are teaching right now.')
                ->default(false),
            'employees_only' => $schema->boolean()
                ->description('Drop external teachers who are not university employees, e.g. lecturers from companies.')
                ->default(false),
            ...$this->paginationInputSchema($schema, 'teachers'),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $nullableString = fn () => $schema->anyOf([$schema->string()])->nullable();

        return [
            ...$this->paginationOutputSchema($schema, 'teachers'),
            'teachers' => $schema->array()
                ->description('Matching people, best match first: whole-surname matches, then surname prefixes, then anything else, with active people ahead of inactive ones within each group.')
                ->items($schema->object([
                    'teacher_id' => $schema->integer()->description('ucitIdno. One person occasionally holds two, so two rows with the same name and email may be the same human.'),
                    'name' => $schema->string()->description('Display name with titles, e.g. "doc. Ing. Jan Novák, Ph.D."'),
                    'first_name' => $nullableString()->description('First name.'),
                    'last_name' => $nullableString()->description('Surname.'),
                    'titles_before' => $nullableString()->description('Titles before the name, e.g. "doc. Ing."'),
                    'titles_after' => $nullableString()->description('Titles after the name, e.g. "Ph.D."'),
                    'department' => $nullableString()->description('Home department code, e.g. "KIV". Null for many external teachers.'),
                    'other_department' => $nullableString()->description('Further workplace codes, comma-separated, when STAG records any, e.g. "FF,KSA".'),
                    'email' => $nullableString()->description('Work email, when STAG has one. Missing for most people.'),
                    'active' => $schema->boolean()->description('STAG\'s platnost flag. False does not mean the person has left: student demonstrators and doctoral assistants are often false while teaching.'),
                    'employee' => $schema->boolean()->description('A university employee, as opposed to an external teacher.'),
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
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'min:2', 'max:100', 'required_without:department'],
            'department' => ['nullable', 'string', 'regex:/^[\pL\pN-]+$/u', 'max:10', 'required_without:name'],
            'active_only' => ['nullable', 'boolean'],
            'employees_only' => ['nullable', 'boolean'],
            ...$this->paginationRules(),
        ]);

        $name = $validated['name'] ?? null;

        if ($name !== null && TeacherSearch::tokens($name) === []) {
            return Response::error('The name has no letters or digits to search by.');
        }

        $department = isset($validated['department']) ? mb_strtoupper($validated['department']) : null;

        $teachers = (new TeacherDirectory($stag))->search(
            $name,
            $department,
            (bool) ($validated['active_only'] ?? false),
            (bool) ($validated['employees_only'] ?? false),
        );

        return Response::structured(
            $this->paginate($teachers, $validated, 'teachers', $this->toTeacher(...)),
        );
    }

    protected function paginationDefault(): int
    {
        return 25;
    }

    protected function paginationMax(): int
    {
        return 200;
    }

    /**
     * @param  array<string, mixed>  $row  a TeacherDirectory row
     * @return array<string, mixed>
     */
    private function toTeacher(array $row): array
    {
        $fullName = implode(' ', array_filter([$row['titles_before'], $row['first_name'], $row['last_name']]));

        return [
            'teacher_id' => $row['id'],
            'name' => $row['titles_after'] !== null ? "{$fullName}, {$row['titles_after']}" : $fullName,
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'titles_before' => $row['titles_before'],
            'titles_after' => $row['titles_after'],
            'department' => $row['department'],
            'other_department' => $row['other_department'],
            'email' => $row['email'],
            'active' => $row['active'],
            'employee' => $row['employee'],
        ];
    }
}

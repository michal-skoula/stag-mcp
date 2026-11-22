<?php

namespace App\Mcp\Tools;

use App\Contracts\StagClient;
use App\Exceptions\ExamRegistrationException;
use App\Exceptions\StagException;
use App\Mcp\Concerns\DescribesExamTerms;
use App\Mcp\Concerns\RequiresStagLogin;
use App\Mcp\Concerns\ResolvesStagIdentity;
use App\Mcp\Enums\ExamRegistrationAction;
use App\Services\ExamTerms\ExamRegistrar;
use App\Services\ExamTerms\ExamTerm;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('prepare-exam-registration')]
#[Title('Prepare Exam Registration')]
#[Description('Step 1 of 2 for registering for an exam term, unregistering from one, or joining or leaving its waiting queue. Changes nothing. Returns the term, any warnings, and a single-use confirmation_token valid for 5 minutes. Before calling confirm-exam-registration, tell the user the action, subject, date, time and room, plus every warning, and wait for an explicit yes. Get term_id from list-exam-dates.')]
#[IsReadOnly]
class PrepareExamRegistrationTool extends Tool
{
    use DescribesExamTerms;
    use RequiresStagLogin;
    use ResolvesStagIdentity;

    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Fterminy

    /**
     * Exam registration writes to production STAG, so it stays hidden until
     * the owner switches it on.
     */
    public function shouldRegister(): bool
    {
        return config('services.stag.exam_registration');
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'term_id' => $schema->integer()
                ->description('The term_id from list-exam-dates.')
                ->required(),
            'action' => $schema->string()
                ->enum(array_column(ExamRegistrationAction::cases(), 'value'))
                ->description('"register", "unregister", "join_queue" (wait for a place on a full term) or "leave_queue".')
                ->required(),
            'os_cislo' => $schema->string()
                ->description('Personal number (osCislo), for accounts holding more than one study. Resolved automatically when omitted.'),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->description('The prepared action.'),
            'term' => $this->examTermSchema($schema),
            'warnings' => $schema->array()
                ->description('Reasons STAG may refuse or the user may regret it. Tell the user every one before confirming.')
                ->items($schema->string()),
            'confirmation' => $schema->object([
                'token' => $schema->string()->description('Pass to confirm-exam-registration. Works once.'),
                'expires_at' => $schema->string()->description('"Y-m-d H:i:s" Prague time.'),
                'restate' => $schema->object([
                    'subject' => $schema->string()->description('e.g. "KIV/DB1".'),
                    'date' => $schema->anyOf([$schema->string()])->nullable()->description('ISO date.'),
                    'time_from' => $schema->anyOf([$schema->string()])->nullable()->description('HH:MM.'),
                    'room' => $schema->anyOf([$schema->string()])->nullable()->description('Building and room, e.g. "UU 108".'),
                ])->description('What to tell the user and then pass back to confirm-exam-registration unchanged.'),
            ]),
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
            'term_id' => ['required', 'integer', 'min:1'],
            'action' => ['required', 'string', Rule::enum(ExamRegistrationAction::class)],
            'os_cislo' => ['string', 'nullable'],
        ]);

        $osCislo = $validated['os_cislo'] ?? $this->resolveOsCislo($stag);

        if ($osCislo === null) {
            return Response::error('This account has no student role, so it cannot register for exam terms.');
        }

        try {
            $prepared = (new ExamRegistrar($stag))->prepare(
                $request->user('sanctum')->getAuthIdentifier(),
                $osCislo,
                $validated['term_id'],
                ExamRegistrationAction::from($validated['action']),
            );
        } catch (ExamRegistrationException $e) {
            return Response::error($e->getMessage());
        }

        $pending = $prepared['pending'];

        return Response::structured([
            'action' => $pending->action->value,
            'term' => $prepared['term']->toArray(CarbonImmutable::now(ExamTerm::TIMEZONE)),
            'warnings' => $prepared['warnings'],
            'confirmation' => [
                'token' => $pending->token,
                'expires_at' => $prepared['expires_at']->format('Y-m-d H:i:s'),
                'restate' => $pending->facts,
            ],
        ]);
    }
}

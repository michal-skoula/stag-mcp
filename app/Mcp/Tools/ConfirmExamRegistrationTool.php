<?php

namespace App\Mcp\Tools;

use App\Contracts\StagClient;
use App\Exceptions\ExamRegistrationException;
use App\Exceptions\StagException;
use App\Mcp\Concerns\DescribesExamTerms;
use App\Mcp\Concerns\RequiresStagLogin;
use App\Services\ExamTerms\ExamRegistrar;
use App\Services\ExamTerms\ExamTerm;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('confirm-exam-registration')]
#[Title('Confirm Exam Registration')]
#[Description('Step 2 of 2: makes the change prepared by prepare-exam-registration in the real IS/STAG. Call it only after the user explicitly agreed to the exact action, subject, date, time and room you told them. Pass those four back as prepare returned them in confirmation.restate. A mismatch, an expired or reused token, or a term that moved since prepare cancels the call without changing anything. Never retry on an error without preparing again.')]
#[IsDestructive]
class ConfirmExamRegistrationTool extends Tool
{
    use DescribesExamTerms;
    use RequiresStagLogin;

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
            'confirmation_token' => $schema->string()
                ->description('confirmation.token from prepare-exam-registration.')
                ->required(),
            'subject' => $schema->string()
                ->description('The subject you told the user, e.g. "KIV/DB1".')
                ->required(),
            'date' => $schema->string()
                ->description('The date you told the user, ISO Y-m-d.')
                ->required(),
            'time_from' => $schema->string()
                ->description('The start time you told the user, HH:MM.')
                ->required(),
            'room' => $schema->string()
                ->description('The room you told the user, e.g. "UU 108".')
                ->required(),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->description('The action STAG carried out.'),
            'stag_message' => $schema->string()->description('STAG\'s answer, "OK" on success.'),
            'term' => $schema->anyOf([$this->examTermSchema($schema)])->nullable()
                ->description('The term with the caller\'s state read back after the change, or null if it could not be read. The change went through either way.'),
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
            'confirmation_token' => ['required', 'string'],
            'subject' => ['required', 'string'],
            'date' => ['required', 'string'],
            'time_from' => ['required', 'string'],
            'room' => ['required', 'string'],
        ]);

        try {
            $result = (new ExamRegistrar($stag))->confirm(
                $request->user('sanctum')->getAuthIdentifier(),
                $validated['confirmation_token'],
                $validated,
            );
        } catch (ExamRegistrationException $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured([
            'action' => $result['action']->value,
            'stag_message' => $result['stag_message'],
            'term' => $result['term']?->toArray(CarbonImmutable::now(ExamTerm::TIMEZONE)),
        ]);
    }
}

<?php

namespace App\Mcp\Tools;

use App\Contracts\StagClient;
use App\Exceptions\StagException;
use App\Mcp\Concerns\RequiresStagLogin;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('mark-notifications-read')]
#[Title('Mark notifications read')]
#[Description('Marks STAG notifications (oznámení) as read, the same as opening them in the portal. Call it once the user has seen them, or asks to clear them. Needs notification_ids, the id of each notification as list-notifications returns it. Marking one that is already read is harmless.')]
#[IsIdempotent]
class MarkNotificationsReadTool extends Tool
{
    use RequiresStagLogin;

    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Foznameni

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'notification_ids' => $schema->array()
                ->description('Ids of the notifications to mark as read, from list-notifications.')
                ->items($schema->integer())
                ->min(1)
                ->required(),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'count' => $schema->integer()
                ->description('How many notifications were marked as read.'),
            'notification_ids' => $schema->array()
                ->description('The ids STAG accepted.')
                ->items($schema->integer()),
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
            'notification_ids' => ['required', 'array', 'min:1'],
            'notification_ids.*' => ['integer'],
        ], [
            'notification_ids' => 'notification_ids is required and must be a list of at least one notification id from list-notifications, e.g. [123456].',
            'notification_ids.*.integer' => 'Every entry in notification_ids must be a whole-number notification id from list-notifications.',
        ]);

        $ids = array_values(array_unique(array_map(intval(...), $validated['notification_ids'])));

        $stag->put('oznameni/precteno', ['notiIdno' => $ids]);

        // Getting here means STAG accepted the call; every failure path throws.
        return Response::structured([
            'count' => count($ids),
            'notification_ids' => $ids,
        ]);
    }
}

<?php

namespace App\Mcp\Tools;

use App\Clients\StagClient;
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
#[Title('Mark Notifications Read')]
#[Description('Marks one or more STAG notifications as read. Get the ids from the list-notifications tool.')]
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
            'ids' => $schema->array()
                ->description('STAG notification ids to mark as read, as returned by the list-notifications tool.')
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
            'ids' => $schema->array()
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
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $ids = array_values(array_unique(array_map(intval(...), $validated['ids'])));

        $stag->put('oznameni/precteno', ['notiIdno' => $ids]);

        // Getting here means STAG accepted the call; every failure path throws.
        return Response::structured([
            'count' => count($ids),
            'ids' => $ids,
        ]);
    }
}

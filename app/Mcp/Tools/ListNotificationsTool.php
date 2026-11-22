<?php

namespace App\Mcp\Tools;

use App\Contracts\StagClient;
use App\Exceptions\StagException;
use App\Mcp\Concerns\RequiresStagLogin;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-notifications')]
#[Title('List Notifications')]
#[Description('Lists notifications sent by STAG.')]
#[IsReadOnly]
class ListNotificationsTool extends Tool
{
    use RequiresStagLogin;

    // Docs: https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2Foznameni

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'show_read' => $schema->boolean()
                ->description('Also show read notifications. Returns only unread by default.')
                ->default(false),
            'newer_than' => $schema->string()
                ->description('Only show notifications after a given date. YYYY-MM-DD format.'),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'count' => $schema->integer()
                ->description('How many notifications were returned.'),
            'notifications' => $schema->array()
                ->description('The notifications, newest first.')
                ->items($schema->object([
                    'id' => $schema->integer()->description('STAG notification id.'),
                    'subject' => $schema->string()->description('Subject line.'),
                    'message' => $schema->string()->description('Body of the notification.'),
                    'sent_at' => $schema->anyOf([$schema->string()])->description('ISO-8601 timestamp, or null.')->nullable(),
                    'read_at' => $schema->anyOf([$schema->string()])->description('ISO-8601 timestamp, or null when unread.')->nullable(),
                    'url' => $schema->anyOf([$schema->string()])->description('Link STAG attached to the notification, or null.')->nullable(),
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
            'show_read' => ['boolean', 'nullable'],
            'newer_than' => ['date_format:Y-m-d', 'nullable'],
        ]);

        // STAG parses these in Java, where only the literal "true" is truthy. A PHP
        // bool would serialize to "1" and silently read as false.
        $params = [
            'jenNeprectene' => $this->toStagBoolean(! ($validated['show_read'] ?? false)),
        ];

        if (isset($validated['newer_than'])) {
            $params['fromTimestamp'] = Carbon::createFromFormat('Y-m-d', $validated['newer_than'])
                ->startOfDay()
                ->getTimestampMs();
        }

        $notifications = $stag->get('oznameni/list', $params);

        return Response::structured([
            'count' => count($notifications),
            'notifications' => array_map($this->toNotification(...), $notifications),
        ]);
    }

    /**
     * STAG returns Czech keys, per-row identity columns the caller already knows,
     * and dates as "3.9.2026 13:54" strings. Trim to what is useful and make the
     * timestamps machine-readable.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function toNotification(array $row): array
    {
        return [
            'id' => $row['notiIdno'],
            'subject' => $row['predmet'],
            'message' => $row['zprava'],
            'sent_at' => $this->toIso8601($row['odeslano']['value'] ?? null),
            'read_at' => $this->toIso8601($row['precteno']['value'] ?? null),
            'url' => $row['url'] ?? null,
        ];
    }

    private function toStagBoolean(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    private function toIso8601(?string $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return Carbon::createFromFormat('j.n.Y H:i', $date)?->toIso8601String();
    }
}

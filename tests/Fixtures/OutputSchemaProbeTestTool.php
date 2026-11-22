<?php

namespace Tests\Fixtures;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * Returns whatever `credits` it is given, against a schema that declares an
 * integer or null, so the suite-wide output-schema check can be seen to fire.
 */
#[Name('output-schema-probe')]
#[Description('Test fixture. Echoes credits back as structured content.')]
class OutputSchemaProbeTestTool extends Tool
{
    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'teaching' => $schema->object([
                'credits' => $schema->anyOf([$schema->integer()])->nullable(),
            ]),
        ];
    }

    public function handle(Request $request): ResponseFactory|Response
    {
        return Response::structured(['teaching' => ['credits' => $request->get('credits')]]);
    }
}

<?php

namespace Tests\Support;

use Generator;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use PHPUnit\Framework\Assert;

/**
 * The tools/call handler, plus an output-schema check on every successful call.
 *
 * Bound in place of CallTool for the whole test suite, so every tool test
 * also proves its fixture's output would pass a real MCP client's validation.
 */
class SchemaCheckingCallTool extends CallTool
{
    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        $response = parent::handle($request, $context);

        if ($response instanceof JsonRpcResponse) {
            $this->assertMatchesOutputSchema($response, $request, $context);
        }

        return $response;
    }

    private function assertMatchesOutputSchema(JsonRpcResponse $response, JsonRpcRequest $request, ServerContext $context): void
    {
        $result = $response->toArray()['result'] ?? [];

        if (($result['isError'] ?? false) || ! array_key_exists('structuredContent', $result)) {
            return;
        }

        $tool = $context->tools()->first(fn ($tool): bool => $tool->name() === $request->params['name']);
        $schema = $tool?->toArray()['outputSchema'] ?? null;

        if ($schema === null) {
            return;
        }

        $decode = fn (mixed $value) => json_decode(json_encode($value, JSON_THROW_ON_ERROR), false, flags: JSON_THROW_ON_ERROR);

        $errors = OutputSchemaValidator::validate(
            $decode($result['structuredContent']),
            json_decode(json_encode($schema, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR),
        );

        Assert::assertEmpty($errors, "{$tool->name()} returned structured content that does not match its output schema:\n".implode("\n", $errors));
    }
}

<?php

namespace App\Mcp\Methods;

use App\Events\McpToolCalled;
use Generator;
use Illuminate\Support\Facades\Context;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * `tools/call` with the tool name put into the log context and a
 * McpToolCalled event fired afterwards.
 *
 * Instrumenting here covers every tool, present and future, without any of
 * them knowing about it. The name in the context lets the STAG client and the
 * error reporter say which tool a call belonged to.
 */
class InstrumentedCallTool extends CallTool
{
    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        $tool = $request->params['name'] ?? null;

        if (! is_string($tool)) {
            return parent::handle($request, $context);
        }

        $started = hrtime(true);
        $failed = true;
        Context::add('mcp_tool', $tool);

        try {
            $response = parent::handle($request, $context);
            $failed = $response instanceof JsonRpcResponse ? $this->isFailure($response) : false;

            return $response;
        } finally {
            event(new McpToolCalled(
                $tool,
                auth('sanctum')->id(),
                round((hrtime(true) - $started) / 1e6, 1),
                $failed,
            ));
            Context::forget('mcp_tool');
        }
    }

    private function isFailure(JsonRpcResponse $response): bool
    {
        $payload = $response->toArray();

        return isset($payload['error']) || ($payload['result']['isError'] ?? false) === true;
    }
}

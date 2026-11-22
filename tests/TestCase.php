<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Mcp\Server\Methods\CallTool;
use Tests\Support\SchemaCheckingCallTool;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(CallTool::class, SchemaCheckingCallTool::class);
    }
}

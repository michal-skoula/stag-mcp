<?php

use PHPUnit\Framework\AssertionFailedError;
use Tests\Fixtures\OutputSchemaProbeTestTool;
use Tests\Fixtures\StagLoginTestServer;
use Tests\Support\OutputSchemaValidator;

it('passes structured content that matches the output schema', function (mixed $credits) {
    StagLoginTestServer::tool(OutputSchemaProbeTestTool::class, ['credits' => $credits])->assertOk();
})->with([6, null]);

it('fails the test when structured content does not match the output schema', function () {
    StagLoginTestServer::tool(OutputSchemaProbeTestTool::class, ['credits' => '6']);
})->throws(AssertionFailedError::class, 'data/teaching/credits must match a schema in anyOf');

it('reports a type mismatch with the path to the field', function () {
    $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];

    expect(OutputSchemaValidator::validate((object) ['name' => 6], $schema))
        ->toBe(['data/name must be string, got int']);
});

it('reports a key the schema does not declare', function () {
    $schema = ['type' => 'object', 'properties' => ['department' => ['type' => 'string']]];

    expect(OutputSchemaValidator::validate((object) ['katedra' => 'KIV'], $schema))
        ->toBe(['data/katedra is not declared in the output schema']);
});

it('treats an empty PHP array as a JSON list, not an object', function () {
    $schema = ['type' => 'object', 'properties' => []];

    expect(OutputSchemaValidator::validate([], $schema))->toBe(['data must be object, got array']);
});

it('checks every list item against items', function () {
    $schema = ['type' => 'array', 'items' => ['type' => 'integer']];

    expect(OutputSchemaValidator::validate([1, 'two', 3], $schema))->toBe(['data/1 must be integer, got string']);
});

it('rejects a value outside an enum', function () {
    expect(OutputSchemaValidator::validate('maybe', ['type' => 'string', 'enum' => ['class', 'exam']]))
        ->toBe(['data must be one of ["class","exam"], got "maybe"']);
});

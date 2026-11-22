<?php

namespace Tests\Support;

/**
 * Checks a tool's structured content against its declared output schema.
 *
 * MCP clients validate `structuredContent` against `outputSchema` and reject
 * the whole call on a mismatch, with messages like "data/teaching/kredity must
 * be string". Nothing on the server side runs that check, so a type the
 * schema gets wrong only shows up against a real client. This covers the
 * subset of JSON Schema the tools in app/Mcp/Tools emit: type, anyOf, enum,
 * properties, required and items.
 *
 * It is stricter than a client in one way: a property the schema does not
 * declare is an error, so a key renamed in the mapping but not in the schema
 * is caught.
 */
final class OutputSchemaValidator
{
    /**
     * @param  array<string, mixed>  $schema
     * @return list<string> one message per violation, empty when the value matches
     */
    public static function validate(mixed $value, array $schema, string $path = 'data'): array
    {
        if (isset($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $option) {
                if (self::validate($value, $option, $path) === []) {
                    return [];
                }
            }

            return ["{$path} must match a schema in anyOf"];
        }

        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            $matching = array_filter($types, fn (string $type) => self::isOfType($value, $type));

            if ($matching === []) {
                return ["{$path} must be ".implode(' or ', $types).', got '.get_debug_type($value)];
            }
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            return ["{$path} must be one of ".json_encode($schema['enum']).', got '.json_encode($value)];
        }

        if ($value instanceof \stdClass) {
            return self::validateObject((array) $value, $schema, $path);
        }

        if (is_array($value) && isset($schema['items'])) {
            $errors = [];

            foreach ($value as $index => $item) {
                $errors = [...$errors, ...self::validate($item, $schema['items'], "{$path}/{$index}")];
            }

            return $errors;
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private static function validateObject(array $object, array $schema, string $path): array
    {
        $properties = $schema['properties'] ?? [];
        $errors = [];

        foreach ($schema['required'] ?? [] as $required) {
            if (! array_key_exists($required, $object)) {
                $errors[] = "{$path} must have required property '{$required}'";
            }
        }

        foreach ($object as $key => $item) {
            if (! isset($properties[$key])) {
                $errors[] = "{$path}/{$key} is not declared in the output schema";

                continue;
            }

            $errors = [...$errors, ...self::validate($item, $properties[$key], "{$path}/{$key}")];
        }

        return $errors;
    }

    /**
     * Expects the value as json_decode() without $associative gives it, so a
     * JSON object is a stdClass and an empty PHP array, which encodes as [],
     * is a list. That mirrors what the client actually receives.
     */
    private static function isOfType(mixed $value, string $type): bool
    {
        return match ($type) {
            'null' => $value === null,
            'string' => is_string($value),
            'boolean' => is_bool($value),
            'integer' => is_int($value) || (is_float($value) && floor($value) === $value),
            'number' => is_int($value) || is_float($value),
            'array' => is_array($value),
            'object' => $value instanceof \stdClass,
            default => false,
        };
    }
}

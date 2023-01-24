<?php

declare(strict_types=1);

namespace ADS\JsonImmutableObjects\Tests\Object;

use ADS\JsonImmutableObjects\JsonSchemaAwareRecordLogic;
use EventEngine\JsonSchema\JsonSchemaAwareRecord;

class TestObjectWithInvalidDefaultProperties implements JsonSchemaAwareRecord
{
    use JsonSchemaAwareRecordLogic;

    private string $test;

    /** @return array<int, string> */
    private static function __defaultProperties(): array
    {
        return ['test'];
    }
}

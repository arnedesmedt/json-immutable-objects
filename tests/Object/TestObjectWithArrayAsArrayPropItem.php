<?php

declare(strict_types=1);

namespace ADS\JsonImmutableObjects\Tests\Object;

use ADS\JsonImmutableObjects\JsonSchemaAwareRecordLogic;
use EventEngine\JsonSchema\JsonSchemaAwareRecord;

class TestObjectWithArrayAsArrayPropItem implements JsonSchemaAwareRecord
{
    use JsonSchemaAwareRecordLogic;

    /** @var array<string> */
    private array $list;

    /** @return array<string, string> */
    private static function arrayPropItemTypeMap(): array
    {
        return ['list' => 'array'];
    }
}

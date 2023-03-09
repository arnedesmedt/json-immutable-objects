<?php

declare(strict_types=1);

namespace ADS\JsonImmutableObjects\Tests\Object;

use ADS\JsonImmutableObjects\HasPropertyExamples;
use ADS\JsonImmutableObjects\JsonSchemaAwareRecordLogic;
use EventEngine\JsonSchema\JsonSchemaAwareRecord;

class TestObject implements JsonSchemaAwareRecord, HasPropertyExamples
{
    use JsonSchemaAwareRecordLogic;

    /** Testdoc */
    private string $test;
    private TestStringVO $testString;
    /** @var array<string> */
    private array $list;
    private string|null $nullable = null;
    private int $normalValue = 5;
    private TestIntVO $valueDefaultViaFunction;
    /** @example {"test": "test"} */
    private TestSubObject|null $subObject = null;
    /** @var array<TestSubObject> */
    private array $subObjects = [];

    public function normalValue(): int
    {
        return $this->normalValue;
    }

    public function valueDefaultViaFunction(): TestIntVO
    {
        return $this->valueDefaultViaFunction;
    }

    /** @return array<string, string> */
    private static function arrayPropItemTypeMap(): array
    {
        return [
            'list' => 'string',
            'subObjects' => TestSubObject::class,
        ];
    }

    /** @return array<string, mixed> */
    private static function maxValues(): array
    {
        return ['normalValue' => 10];
    }

    /** @return array<string, mixed> */
    private static function __defaultProperties(): array
    {
        return [
            'valueDefaultViaFunction' => TestIntVO::fromInt(0),
        ];
    }

    /** @return array<string, mixed> */
    public static function examples(): array
    {
        return [
            'test' => TestStringVO::fromString('test'),
        ];
    }
}

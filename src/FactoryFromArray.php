<?php

declare(strict_types=1);

namespace ADS\JsonImmutableObjects;

use EventEngine\JsonSchema\JsonSchemaAwareRecord;

trait FactoryFromArray
{
    /** @return class-string<JsonSchemaAwareRecord> */
    abstract protected static function modelClass(): string;

    /** @param array<string, mixed> $array */
    public static function fromArray(array $array): mixed
    {
        return self::modelClass()::fromArray($array);
    }

    /** @param array<string, mixed> $array */
    public static function fromRecordData(array $array): mixed
    {
        return self::modelClass()::fromRecordData($array);
    }
}

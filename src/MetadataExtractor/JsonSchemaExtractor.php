<?php

declare(strict_types=1);

namespace ADS\JsonImmutableObjects\MetadataExtractor;

use EventEngine\JsonSchema\JsonSchema;
use EventEngine\JsonSchema\JsonSchemaAwareCollection;
use EventEngine\JsonSchema\JsonSchemaAwareRecord;
use EventEngine\JsonSchema\Type;
use EventEngine\Schema\TypeSchema;
use LogicException;
use ReflectionClass;

use function sprintf;

class JsonSchemaExtractor
{
    /** @param class-string<object> $class */
    public function fromClass(string $class): TypeSchema
    {
        return $this->fromReflectionClass(new ReflectionClass($class));
    }

    /** @param ReflectionClass<object> $reflectionClass */
    public function fromReflectionClass(
        ReflectionClass $reflectionClass,
    ): TypeSchema {
        if ($reflectionClass->implementsInterface(JsonSchemaAwareRecord::class)) {
            /** @var class-string<JsonSchemaAwareRecord> $message */
            $message = $reflectionClass->getName();

            return $message::__schema();
        }

        if ($reflectionClass->implementsInterface(JsonSchemaAwareCollection::class)) {
            /** @var class-string<JsonSchemaAwareCollection> $message */
            $message = $reflectionClass->getName();

            /** @var Type $itemSchema */
            $itemSchema = $message::__itemSchema();

            return JsonSchema::array($itemSchema);
        }

        throw new LogicException(
            sprintf(
                'No schema found for message \'%s\'. ' .
                'Implement the JsonSchemaAwareRecord or JsonSchemaAwareCollection interface.',
                $reflectionClass->getName(),
            ),
        );
    }
}

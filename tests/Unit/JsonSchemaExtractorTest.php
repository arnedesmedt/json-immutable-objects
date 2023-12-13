<?php

declare(strict_types=1);

namespace ADS\JsonImmutableObjects\Tests\Unit;

use ADS\JsonImmutableObjects\MetadataExtractor\JsonSchemaExtractor;
use ADS\JsonImmutableObjects\Tests\Object\Immutable\TestCollection;
use ADS\JsonImmutableObjects\Tests\Object\Immutable\TestImmutable;
use EventEngine\JsonSchema\Type\ArrayType;
use EventEngine\JsonSchema\Type\ObjectType;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class JsonSchemaExtractorTest extends TestCase
{
    private JsonSchemaExtractor $jsonSchemaExtractor;

    protected function setUp(): void
    {
        $this->jsonSchemaExtractor = new JsonSchemaExtractor();
    }

    public function testRecordFromReflectionClass(): void
    {
        $schema = $this->jsonSchemaExtractor->fromReflectionClass(
            new ReflectionClass(TestImmutable::class),
        );

        $this->assertInstanceOf(ObjectType::class, $schema);
    }

    public function testCollectionFromReflectionClass(): void
    {
        $schema = $this->jsonSchemaExtractor->fromReflectionClass(
            new ReflectionClass(TestCollection::class),
        );

        $this->assertInstanceOf(ArrayType::class, $schema);
    }

    public function testNonExistingSchemaExtractor(): void
    {
        $this->expectExceptionMessage(
            'No schema found for message \'ADS\JsonImmutableObjects\MetadataExtractor\JsonSchemaExtractor\'. ' .
            'Implement the JsonSchemaAwareRecord or JsonSchemaAwareCollection interface.',
        );

        $this->jsonSchemaExtractor->fromReflectionClass(
            new ReflectionClass(JsonSchemaExtractor::class),
        );
    }
}

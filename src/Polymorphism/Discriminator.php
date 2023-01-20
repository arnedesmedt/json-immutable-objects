<?php

declare(strict_types=1);

namespace ADS\JsonImmutableObjects\Polymorphism;

use EventEngine\Data\ImmutableRecord;
use EventEngine\JsonSchema\JsonSchemaAwareRecord;

interface Discriminator extends JsonSchemaAwareRecord
{
    /** @param array<string, mixed> $recordData */
    public static function fromRecordData(array $recordData): self;

    /** @param array<string, mixed> $nativeData */
    public static function fromArray(array $nativeData): self;

    public function value(): ImmutableRecord;

    public static function propertyName(): string;

    /** @return array<string, class-string<JsonSchemaAwareRecord>> */
    public static function mapping(): array;

    /** @return array<class-string<JsonSchemaAwareRecord>> */
    public static function jsonSchemaAwareRecords(): array;

    /** @return array<string, mixed> */
    public function toArray(): array;

    /** @param array<string, mixed> $recordData */
    public function with(array $recordData): self;
}

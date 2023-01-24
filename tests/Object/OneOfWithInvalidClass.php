<?php

declare(strict_types=1);

namespace ADS\JsonImmutableObjects\Tests\Object;

use ADS\JsonImmutableObjects\Polymorphism\Discriminator;
use ADS\JsonImmutableObjects\Polymorphism\DiscriminatorLogic;

class OneOfWithInvalidClass implements Discriminator
{
    use DiscriminatorLogic;

    public static function propertyName(): string
    {
        return 'type';
    }

    /** @return array<string, string> */
    public static function mapping(): array
    {
        return [
            'one' => 'invalidClass',
            'two' => OneOfWithDiscriminatorTwo::class,
        ];
    }

    /** @return array<string> */
    public static function jsonSchemaAwareRecords(): array
    {
        return [
            'invalidClass',
            OneOfWithDiscriminatorTwo::class,
        ];
    }
}

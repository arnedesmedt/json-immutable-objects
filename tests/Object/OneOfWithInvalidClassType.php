<?php

declare(strict_types=1);

namespace ADS\JsonImmutableObjects\Tests\Object;

use ADS\JsonImmutableObjects\Polymorphism\Discriminator;
use ADS\JsonImmutableObjects\Polymorphism\DiscriminatorLogic;

class OneOfWithInvalidClassType implements Discriminator
{
    use DiscriminatorLogic;

    public static function propertyName(): string
    {
        return 'type';
    }

    /** @return array<string, class-string> */
    public static function mapping(): array
    {
        return [
            'one' => TestIntVO::class,
            'two' => OneOfWithDiscriminatorTwo::class,
        ];
    }

    /** @return array<class-string> */
    public static function jsonSchemaAwareRecords(): array
    {
        return [
            TestIntVO::class,
            OneOfWithDiscriminatorTwo::class,
        ];
    }
}

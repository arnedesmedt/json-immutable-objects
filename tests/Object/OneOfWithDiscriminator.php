<?php

declare(strict_types=1);

namespace ADS\JsonImmutableObjects\Tests\Object;

use ADS\JsonImmutableObjects\Polymorphism\Discriminator;
use ADS\JsonImmutableObjects\Polymorphism\DiscriminatorLogic;

class OneOfWithDiscriminator implements Discriminator
{
    use DiscriminatorLogic;

    public static function propertyName(): string
    {
        return 'type';
    }

    /** @inheritDoc */
    public static function jsonSchemaAwareRecords(): array
    {
        return [
            OneOfWithDiscriminatorOne::class,
            OneOfWithDiscriminatorTwo::class,
        ];
    }
}

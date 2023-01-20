<?php

declare(strict_types=1);

namespace ADS\JsonImmutableObjects\Tests\Object;

use ADS\JsonImmutableObjects\FactoryFromArray;

class TestObjectFactory
{
    use FactoryFromArray;

    protected static function modelClass(): string
    {
        return TestObject::class;
    }
}

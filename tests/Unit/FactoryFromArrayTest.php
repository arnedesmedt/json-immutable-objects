<?php

declare(strict_types=1);

namespace ADS\JsonImmutableObjects\Tests\Unit;

use ADS\JsonImmutableObjects\Tests\Object\TestObject;
use ADS\JsonImmutableObjects\Tests\Object\TestObjectFactory;
use PHPUnit\Framework\TestCase;

class FactoryFromArrayTest extends TestCase
{
    public function testFactoryFromArray(): void
    {
        $test = TestObjectFactory::fromArray(
            [
                'test' => 'test',
                'nonExistingProperty' => 'test',
                'list' => ['test'],
                'subObject' => ['test' => 'test'],
                'subObjects' => [
                    ['test' => 'test'],
                ],
            ],
        );

        $this->assertInstanceOf(TestObject::class, $test);
    }
}

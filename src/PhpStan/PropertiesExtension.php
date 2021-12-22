<?php

declare(strict_types=1);

namespace ADS\JsonImmutableObjects\PhpStan;

use EventEngine\Data\ImmutableRecord;
use PHPStan\Reflection\PropertyReflection;
use PHPStan\Rules\Properties\ReadWritePropertiesExtension;

use function array_key_exists;
use function method_exists;

class PropertiesExtension implements ReadWritePropertiesExtension
{
    public function isAlwaysRead(PropertyReflection $property, string $propertyName): bool
    {
        return $property->getDeclaringClass()->implementsInterface(ImmutableRecord::class);
    }

    public function isAlwaysWritten(PropertyReflection $property, string $propertyName): bool
    {
        return $property->getDeclaringClass()->implementsInterface(ImmutableRecord::class);
    }

    public function isInitialized(PropertyReflection $property, string $propertyName): bool
    {
        $className = $property->getDeclaringClass()->getName();

        if (! method_exists($className, 'defaultProperties')) {
            return false;
        }

        return array_key_exists($propertyName, $className::defaultProperties());
    }
}

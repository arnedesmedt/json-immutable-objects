<?php

declare(strict_types=1);

namespace ADS\JsonImmutableObjects;

use EventEngine\Data\ImmutableRecord;
use EventEngine\Data\SpecialKeySupport;
use InvalidArgumentException;
use ReflectionClass;

use function preg_match;

trait DefaultImmutableProperties
{
    use JsonSchemaAwareRecordLogic;

    private function init(): void
    {
        // phpcs:ignore Squiz.NamingConventions.ValidVariableName.NotCamelCaps
        foreach (self::$__propTypeMap as $key => [$type, $isNative, $isNullable]) {
            if ($isNullable || $isNative) {
                continue;
            }

            $specialKey = $key;

            if ($this instanceof SpecialKeySupport) {
                $specialKey = $this->convertKeyForArray($key);
            }

            if (isset($this->{$specialKey})) {
                continue;
            }

            $reflectionType = new ReflectionClass($type);

            if (! $reflectionType->implementsInterface(ImmutableRecord::class)) {
                continue;
            }

            try {
                $this->{$specialKey} = $type::fromArray([], self::$useMaxValues);
            } catch (InvalidArgumentException $exception) {
                if (! preg_match('/^Missing record data for key/', $exception->getMessage())) {
                    throw $exception;
                }
            }
        }

        self::$useMaxValues = false;
    }
}

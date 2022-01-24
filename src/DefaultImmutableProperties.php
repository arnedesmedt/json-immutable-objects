<?php

//phpcs:disable Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps


declare(strict_types=1);

namespace ADS\JsonImmutableObjects;

use EventEngine\Data\ImmutableRecord;
use EventEngine\Data\SpecialKeySupport;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;

use function preg_match;

trait DefaultImmutableProperties
{
    use JsonSchemaAwareRecordLogic;

    private function init(): void
    {
        // phpcs:ignore Squiz.NamingConventions.ValidVariableName.NotCamelCaps
        foreach (self::$__propTypeMap as $key => [$type, $isNative, $isNullable]) {
            if ($isNative) {
                continue;
            }

            $specialKey = $key;

            if ($this instanceof SpecialKeySupport) {
                $specialKey = $this->convertKeyForArray($key);
            }

            if (isset($this->{$specialKey})) {
                continue;
            }

            try {
                $reflectionType = new ReflectionClass($type);
            } catch (ReflectionException $e) {
                continue;
            }

            if (! $reflectionType->implementsInterface(ImmutableRecord::class)) {
                continue;
            }

            try {
                // phpcs:ignore Squiz.NamingConventions.ValidVariableName.NotCamelCaps
                $this->{$specialKey} = $type::fromArray([], self::$__useMaxValues);
            } catch (InvalidArgumentException $exception) {
                if ($isNullable) {
                    $this->{$specialKey} = null;
                } elseif (! preg_match('/^Missing record data for key/', $exception->getMessage())) {
                    throw $exception;
                }
            }
        }

        // phpcs:ignore Squiz.NamingConventions.ValidVariableName.NotCamelCaps
        self::$__useMaxValues = false;
    }
}

<?php

declare(strict_types=1);

namespace ADS\JsonImmutableObjects;

use ADS\JsonImmutableObjects\Exception\ClassException;
use ADS\Util\ScalarUtil;
use DateTime;
use EventEngine\JsonSchema\AnnotatedType;
use EventEngine\JsonSchema\JsonSchema;
use EventEngine\JsonSchema\JsonSchemaAwareCollection;
use EventEngine\JsonSchema\JsonSchemaAwareRecord;
use EventEngine\JsonSchema\ProvidesValidationRules;
use EventEngine\JsonSchema\Type;
use EventEngine\JsonSchema\Type\StringType;

use function array_map;
use function class_exists;
use function class_implements;
use function class_parents;
use function in_array;
use function is_array;
use function method_exists;
use function strrchr;
use function substr;

/** @SuppressWarnings(PHPMD.CouplingBetweenObjects) */
final class TypeDetector
{
    /** @param string|class-string<JsonSchemaAwareRecord|mixed> $classOrType */
    public static function typeFromClass(
        string $classOrType,
    ): Type {
        $type = self::typeForNonJsonSchemaAwareRecord($classOrType);

        if ($type !== null) {
            return $type;
        }

        return $classOrType::__schema();
    }

    /** @param string|class-string<JsonSchemaAwareRecord|mixed> $classOrType */
    public static function typeFromClassAsReference(string $classOrType): Type
    {
        return self::typeForNonJsonSchemaAwareRecord($classOrType) ?? JsonSchema::typeRef($classOrType);
    }

    /** @param string|class-string<JsonSchemaAwareRecord|mixed> $classOrType */
    private static function typeForNonJsonSchemaAwareRecord(string $classOrType): Type|null
    {
        if (! class_exists($classOrType)) {
            return JsonSchema::typeRef($classOrType);
        }

        $implementations = class_implements($classOrType);

        if (is_array($implementations) && in_array(JsonSchemaAwareRecord::class, $implementations)) {
            return null;
        }

        $type = self::typeFromList($classOrType)
            ?? self::typeFromEnum($classOrType)
            ?? self::typeFromValueObject($classOrType)
            ?? self::typeFromDateTime($classOrType)
            ?? self::typeFromUnknownClass($classOrType);

        if (! $type instanceof AnnotatedType) {
            return $type;
        }

        $type = self::addDefault($classOrType, $type);
        $type = self::addExamples($classOrType, $type);

        return $type;
    }

    /** @param class-string<JsonSchemaAwareCollection|mixed> $class */
    private static function typeFromList(string $class): Type|null
    {
        $implementations = class_implements($class);

        if (! $implementations || ! in_array(JsonSchemaAwareCollection::class, $implementations)) {
            return null;
        }

        return JsonSchema::array($class::__itemSchema(), self::validationRulesFromClass($class));
    }

    /** @param class-string $class */
    private static function typeFromEnum(string $class): Type|null
    {
        $implementations = class_implements($class);
        $parentClasses = class_parents($class);

        if (
            ! $implementations
            || ! in_array('ADS\ValueObjects\EnumValue', $implementations)
            || ! method_exists($class, 'possibleValues')
        ) {
            return null;
        }

        $possibleValues = $class::possibleValues();
        $type = $parentClasses && in_array('ADS\ValueObjects\Implementation\Enum\StringEnumValue', $parentClasses)
            ? JsonSchema::TYPE_STRING
            : JsonSchema::TYPE_INT;

        return JsonSchema::enum($possibleValues, $type);
    }

    /** @param class-string<JsonSchemaAwareCollection|ProvidesValidationRules|mixed> $class */
    private static function typeFromValueObject(string $class): Type|null
    {
        $implementations = class_implements($class);

        if (! $implementations) {
            return null;
        }

        if (in_array('ADS\ValueObjects\BoolValue', $implementations)) {
            return JsonSchema::boolean();
        }

        $validationRules = self::validationRulesFromClass($class);

        if (in_array('ADS\ValueObjects\StringValue', $implementations)) {
            return JsonSchema::string($validationRules);
        }

        if (in_array('ADS\ValueObjects\IntValue', $implementations)) {
            return JsonSchema::integer($validationRules);
        }

        if (in_array('ADS\ValueObjects\FloatValue', $implementations)) {
            return JsonSchema::float($validationRules);
        }

        return null;
    }

    /**
     * @param class-string<ProvidesValidationRules|mixed> $class
     *
     * @return array<string, mixed>|null
     */
    private static function validationRulesFromClass(string $class): array|null
    {
        $implementations = class_implements($class);

        if (! $implementations || ! in_array(ProvidesValidationRules::class, $implementations)) {
            return null;
        }

        return $class::validationRules();
    }

    private static function typeFromDateTime(string $class): Type|null
    {
        $lastPart = strrchr($class, '\\');

        return $lastPart === false && $class === DateTime::class
            ? new Type\StringType([StringType::FORMAT => 'date-time'])
            : null;
    }

    private static function typeFromUnknownClass(string $class): Type
    {
        $lastPart = strrchr($class, '\\');

        if ($lastPart === false) {
            throw ClassException::fullQualifiedClassNameWithoutBackslash($class);
        }

        $ref = substr($lastPart, 1);

        return new Type\TypeRef($ref);
    }

    /** @param class-string $class */
    private static function addDefault(string $class, AnnotatedType $type): AnnotatedType
    {
        $implementations = class_implements($class);

        if (
            ! $implementations
            || ! in_array('ADS\ValueObjects\HasDefault', $implementations)
            || ! method_exists($class, 'defaultValue')
        ) {
            return $type;
        }

        return $type->withDefault(ScalarUtil::toScalar($class::defaultValue()));
    }

    /** @param class-string $class */
    private static function addExamples(string $class, AnnotatedType $type): AnnotatedType
    {
        $implementations = class_implements($class);

        if (
            ! $implementations
            || ! in_array('ADS\ValueObjects\HasExamples', $implementations)
            || ! method_exists($class, 'examples')
        ) {
            return $type;
        }

        $examples = $class::examples();

        if (empty($examples)) {
            return $type;
        }

        return $type->withExamples(
            ...array_map(
                static fn (mixed $example) => ScalarUtil::toScalar($example),
                $examples,
            ),
        );
    }
}

<?php

declare(strict_types=1);

namespace ADS\JsonImmutableObjects;

use ADS\ValueObjects\Implementation\TypeDetector;
use ADS\ValueObjects\ValueObject;
use EventEngine\Data\ImmutableRecord;
use EventEngine\JsonSchema\AnnotatedType;
use EventEngine\JsonSchema\Exception\InvalidArgumentException;
use EventEngine\JsonSchema\JsonSchema;
use EventEngine\JsonSchema\Type;
use LogicException;
use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock\Tags\Generic;
use phpDocumentor\Reflection\DocBlockFactory;
use ReflectionClass;

use function array_diff_key;
use function array_filter;
use function array_flip;
use function array_intersect_key;
use function array_key_exists;
use function array_map;
use function array_merge;
use function class_exists;
use function count;
use function implode;
use function in_array;
use function is_string;
use function preg_replace;
use function sprintf;

use const ARRAY_FILTER_USE_KEY;

trait JsonSchemaAwareRecordLogic
{
    use \EventEngine\JsonSchema\JsonSchemaAwareRecordLogic;

    /**
     * @param array<mixed> $arrayPropTypeMap
     */
    private static function generateSchemaFromPropTypeMap(array $arrayPropTypeMap = []): Type
    {
        if (self::$__propTypeMap === null) {
            self::$__propTypeMap = self::buildPropTypeMap();
        }

        //To keep BC, we cache arrayPropTypeMap internally.
        //New recommended way to provide the map is that
        //one should override the static method self::arrayPropItemTypeMap()
        //Hence, we check if this method returns a non empty array and only in this case cache the map
        if (count($arrayPropTypeMap) && ! count(self::arrayPropItemTypeMap())) {
            self::$__arrayPropItemTypeMap = $arrayPropTypeMap;
        }

        if (self::$__schema === null) {
            $properties = [];
            $optionalProperties = [];
            $defaultProperties = self::defaultProperties();

            foreach (self::$__propTypeMap as $propertyName => [$type, $isScalar, $isNullable]) {
                $properties[$propertyName] = $property = self::baseProperty(
                    $propertyName,
                    $type,
                    $isScalar,
                    $isNullable
                );

                if (! $property instanceof AnnotatedType) {
                    continue;
                }

                $description = self::propertyDescription($propertyName);

                if ($description) {
                    $property = $property->describedAs($description);
                }

                $examples = self::propertyExamples($propertyName);

                if ($examples !== null) {
                    $property = $property->withExamples(...$examples);
                }

                $default = self::propertyDefault($propertyName, $defaultProperties);

                if ($default !== null) {
                    $property = $property->withDefault($default);
                }

                $properties[$propertyName] = $property;
            }

            $optionalProperties = array_flip(self::__optionalProperties());
            $propertiesWithoutOptional = array_diff_key($properties, $optionalProperties);
            $optionalProperties = array_intersect_key($properties, $optionalProperties);

            self::$__schema = JsonSchema::object($propertiesWithoutOptional, $optionalProperties);
        }

        return self::$__schema;
    }

    private static function baseProperty(string $propertyName, string $type, bool $isScalar, bool $isNullable): Type
    {
        if ($isScalar) {
            return JsonSchema::schemaFromScalarPhpType($type, $isNullable);
        }

        if ($type === ImmutableRecord::PHP_TYPE_ARRAY) {
            $arrayPropItemTypeMap = self::arrayPropItemTypeMap();

            if (! array_key_exists($propertyName, $arrayPropItemTypeMap)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Missing array item type in array property map. ' .
                        'Please provide an array item type for property %s.',
                        $propertyName
                    )
                );
            }

            $arrayItemType = $arrayPropItemTypeMap[$propertyName];

            if (self::isScalarType($arrayItemType)) {
                $arrayItemSchema = JsonSchema::schemaFromScalarPhpType($arrayItemType, false);
            } elseif ($arrayItemType === ImmutableRecord::PHP_TYPE_ARRAY) {
                throw new InvalidArgumentException(
                    sprintf(
                        "Array item type of property %s must not be 'array', " .
                        'only a scalar type or an existing class can be used as array item type.',
                        $propertyName
                    )
                );
            } else {
                $arrayItemSchema = self::getTypeFromClass($arrayItemType);
            }

            $schema = JsonSchema::array($arrayItemSchema);
        } else {
            $schema = self::getTypeFromClass($type);
        }

        if (! $isNullable) {
            return $schema;
        }

        return JsonSchema::nullOr($schema);
    }

    private static function docBlockForProperty(string $propertyName): ?DocBlock
    {
        $reflectionProperty = (new ReflectionClass(static::class))->getProperty($propertyName);

        if (! $reflectionProperty->getDocComment()) {
            return null;
        }

        return DocBlockFactory::createInstance()->create($reflectionProperty);
    }

    private static function propertyDescription(string $propertyName): ?string
    {
        $docBlock = self::docBlockForProperty($propertyName);

        if ($docBlock === null) {
            return null;
        }

        $summary = $docBlock->getSummary();
        $description = $docBlock->getDescription()->render();

        if (empty($summary) && empty($description)) {
            return null;
        }

        return implode(
            '<br/>',
            array_filter(
                [
                    $docBlock->getSummary(),
                    $docBlock->getDescription()->render(),
                ]
            )
        );
    }

    /**
     * @return array<mixed>|null
     */
    private static function propertyExamples(string $propertyName): ?array
    {
        $examplesPerProperty = self::allExamples();

        if ($examplesPerProperty[$propertyName] ?? false) {
            $example = $examplesPerProperty[$propertyName];

            if ($example instanceof ValueObject) {
                $example = $example->toValue();
            }

            return [$example];
        }

        $docBlock = self::docBlockForProperty($propertyName);
        $docBlockExamples = $docBlock ? $docBlock->getTagsByName('example') : null;

        if (! empty($docBlockExamples)) {
            return array_map(
                static function (Generic $generic) {
                    return Util::castFromString($generic->getDescription()->render());
                },
                $docBlockExamples
            );
        }

        return null;
    }

    /**
     * @param array<string|int, mixed> $defaultProperties
     *
     * @return mixed|null
     */
    private static function propertyDefault(string $propertyName, array $defaultProperties)
    {
        foreach ($defaultProperties as $defaultPropertyNameOrKey => $optionalPropertyNameOrDefault) {
            $hasDefault = is_string($defaultPropertyNameOrKey);
            $optionalPropertyName = $hasDefault
                ? $defaultPropertyNameOrKey
                : $optionalPropertyNameOrDefault;

            if ($propertyName !== $optionalPropertyName) {
                continue;
            }

            if (! $hasDefault) {
                return null;
            }

            return $optionalPropertyNameOrDefault instanceof ValueObject ?
                $optionalPropertyNameOrDefault->toValue() :
                $optionalPropertyNameOrDefault;
        }
    }

    private static function getTypeFromClass(string $classOrType): Type
    {
        return TypeDetector::getTypeFromClass($classOrType, self::__allowNestedSchema(), isset($_GET['complex']));
    }

    /**
     * @return array<mixed>
     */
    private static function allExamples(): array
    {
        if ((new ReflectionClass(static::class))->implementsInterface(HasPropertyExamples::class)) {
            return static::examples();
        }

        return [];
    }

    /**
     * @return array<string|int, mixed>
     */
    private static function defaultProperties(): array
    {
        $metadataProperties = [
            '__propTypeMap',
            '__schema',
            '__arrayPropItemTypeMap',
        ];

        return array_filter(
            array_merge(
                (new ReflectionClass(static::class))->getDefaultProperties(),
                self::__defaultProperties()
            ),
            static fn ($key) => is_string($key) && ! in_array($key, $metadataProperties),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function __defaultProperties(): array
    {
        return [];
    }

    private static function __allowNestedSchema(): bool
    {
        return true;
    }

    /**
     * @return class-string|null
     */
    private static function getTypeClassNameForState(): ?string
    {
        $stateClass = static::class;

        $typeClass = preg_replace('/(\w)+$/', 'Type', $stateClass);

        if (! class_exists($typeClass)) {
            return null;
        }

        return $typeClass;
    }

    public static function __type(): string
    {
        $typeClassNameForState = static::getTypeClassNameForState();
        if (! is_string($typeClassNameForState)) {
            throw new LogicException('Unable to auto detect the type class for ' . static::class);
        }

        return $typeClassNameForState::typeRefName();
    }
}

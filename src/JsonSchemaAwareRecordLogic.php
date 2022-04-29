<?php

//phpcs:disable Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps


declare(strict_types=1);

namespace ADS\JsonImmutableObjects;

use ADS\Util\ArrayUtil;
use ADS\Util\StringUtil;
use ADS\ValueObjects\Implementation\TypeDetector;
use ADS\ValueObjects\ValueObject;
use EventEngine\Data\ImmutableRecord;
use EventEngine\Data\SpecialKeySupport;
use EventEngine\JsonSchema\AnnotatedType;
use EventEngine\JsonSchema\JsonSchema;
use EventEngine\JsonSchema\Type;
use InvalidArgumentException;
use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock\Tags\Generic;
use phpDocumentor\Reflection\DocBlockFactory;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use Throwable;

use function array_diff_key;
use function array_filter;
use function array_flip;
use function array_intersect_key;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function count;
use function implode;
use function in_array;
use function is_callable;
use function preg_match;
use function sprintf;

use const ARRAY_FILTER_USE_KEY;

trait JsonSchemaAwareRecordLogic
{
    use \EventEngine\JsonSchema\JsonSchemaAwareRecordLogic {
        fromArray as parentFromArray;
        buildPropTypeMap as parentBuildPropTypeMap;
    }

    // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore
    private static bool $__useMaxValues = false;

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

                try {
                    $property = $property->withDefault(
                        self::propertyDefault($propertyName, $defaultProperties)
                    );
                } catch (Throwable $e) {
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
                static fn (Generic $generic) => StringUtil::castFromString($generic->getDescription()->render()),
                $docBlockExamples
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed> $defaultProperties
     */
    public static function propertyDefault(string $propertyName, array $defaultProperties): mixed
    {
        if (! isset($defaultProperties[$propertyName])) {
            throw new RuntimeException('default property not set.');
        }

        $default = $defaultProperties[$propertyName];

        return $default instanceof ValueObject ?
            $default->toValue() :
            $default;
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
     * @return array<string, mixed>
     */
    public static function defaultProperties(): array
    {
        $propertyNames = array_keys(self::buildPropTypeMap());
        $defaultProperties = self::__defaultProperties();

        if (! empty($defaultProperties) && ! ArrayUtil::isAssociative($defaultProperties)) {
            throw new RuntimeException(
                sprintf(
                    'The __defaultProperties method from \'%s\', should be an associative array ' .
                    'where the key is the property and the value is the default value.',
                    static::class
                )
            );
        }

        return array_filter(
            array_merge(
                (new ReflectionClass(static::class))->getDefaultProperties(),
                $defaultProperties
            ),
            static fn ($key) => in_array($key, $propertyNames),
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
     * @param array<mixed> $nativeData
     *
     * @return self
     *
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.ReturnTypeHint.MissingNativeTypeHint
     */
    public static function fromArray(array $nativeData, bool $useMaxValuesAsDefaults = false)
    {
        if ($useMaxValuesAsDefaults && is_callable([static::class, 'maxValues'])) {
            static::$__useMaxValues = true;
            $nativeData = array_merge(static::maxValues(), $nativeData);
        }

        $camelCasedNativeData = ArrayUtil::toCamelCasedKeys($nativeData);
        $propTypeMap = self::buildPropTypeMap();

        $filteredAllowedProperties = array_intersect_key(
            $camelCasedNativeData,
            $propTypeMap
        );

        foreach ($filteredAllowedProperties as $key => $allowedProperty) {
            [$name, $scalar, $allowNull] = $propTypeMap[$key];

            $filteredAllowedProperties[$key] =  ! $allowedProperty instanceof ValueObject || ! $scalar
                ? $allowedProperty
                : $allowedProperty->toValue();
        }

        return self::parentFromArray($filteredAllowedProperties);
    }

    /**
     * @return array<mixed>
     */
    private static function buildPropTypeMap(): array
    {
        $propTypeMap = self::parentBuildPropTypeMap();
        unset($propTypeMap['__useMaxValues']);

        return $propTypeMap;
    }

    /**
     * @return array<string>
     *
     * @inheritDoc
     */
    private static function __optionalProperties(): array
    {
        return array_keys(self::defaultProperties());
    }

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

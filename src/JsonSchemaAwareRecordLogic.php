<?php

//phpcs:disable Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps


declare(strict_types=1);

namespace ADS\JsonImmutableObjects;

use ADS\Util\ArrayUtil;
use ADS\ValueObjects\Implementation\TypeDetector;
use ADS\ValueObjects\ValueObject;
use EventEngine\Data\ImmutableRecord;
use EventEngine\Data\SpecialKeySupport;
use EventEngine\JsonSchema\AnnotatedType;
use EventEngine\JsonSchema\JsonSchema;
use EventEngine\JsonSchema\Type;
use EventEngine\JsonSchema\Type\ObjectType;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

use function array_diff_key;
use function array_filter;
use function array_flip;
use function array_intersect_key;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function assert;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function json_encode;
use function method_exists;
use function sprintf;
use function str_starts_with;

use const ARRAY_FILTER_USE_KEY;
use const JSON_THROW_ON_ERROR;

trait JsonSchemaAwareRecordLogic
{
    use \EventEngine\JsonSchema\JsonSchemaAwareRecordLogic {
        fromArray as parentFromArray;
        setNativeData as parentSetNativeData;
        setRecordData as parentSetRecordData;
        buildPropTypeMap as parentBuildPropTypeMap;
    }

    // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore
    private static bool $__useMaxValues = false;

    /**
     * @param array<mixed> $nativeData
     *
     * @return self
     *
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.ReturnTypeHint.MissingNativeTypeHint
     */
    public static function fromArray(array $nativeData)
    {
        // Convert value objects to native data.
        return self::parentFromArray(
            array_map(
                static fn ($value) => $value instanceof ValueObject ? $value->toValue() : $value,
                $nativeData,
            ),
        );
    }

    /** @param array<string, mixed> $nativeData */
    private function setNativeData(array $nativeData): void
    {
        assert(is_array(self::$__propTypeMap));

        // Filter not allowed properties.
        $filteredNativeData = array_intersect_key(
            $this->convertKeys($nativeData),
            self::$__propTypeMap,
        );

        $this->parentSetNativeData($filteredNativeData);
        $this->addDefaultProperties();
    }

    /** @param array<string, mixed> $recordData */
    private function setRecordData(array $recordData): void
    {
        assert(is_array(self::$__propTypeMap));

        // Filter not allowed properties.
        $filteredRecordData = array_intersect_key(
            $this->convertKeys($recordData),
            self::$__propTypeMap,
        );

        $this->parentSetRecordData($filteredRecordData);
        $this->addDefaultProperties();
    }

    private function addDefaultProperties(): void
    {
        $defaultProperties = self::__defaultProperties();

        foreach ($defaultProperties as $property => $defaultValue) {
            if (! is_string($property)) {
                throw new RuntimeException(
                    sprintf(
                        'The __defaultProperties method from \'%s\', should be an associative array' .
                        'where the key is the property and the value is the default value. Found key \'%d\'.',
                        static::class,
                        $property,
                    ),
                );
            }

            if (isset($this->{$property})) {
                continue;
            }

            $this->{$property} = $defaultValue;
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function convertKeys(array $data): array
    {
        /** @var array<string, mixed> $data */
        $data = ArrayUtil::toCamelCasedKeys($data); // todo remove and use SpecialKeySupport

        if (! $this instanceof SpecialKeySupport) {
            return $data;
        }

        $dataWithConvertedKeys = [];
        foreach ($data as $key => $value) {
            $dataWithConvertedKeys[$this->convertKeyForRecord($key)] = $value;
        }

        return $dataWithConvertedKeys;
    }

    /** @return array<string, mixed> */
    public static function defaultProperties(): array
    {
        $propertyNames     = array_keys(self::buildPropTypeMap());
        $defaultProperties = self::__defaultProperties();

        return array_filter(
            [...(new ReflectionClass(static::class))->getDefaultProperties(), ...$defaultProperties],
            static fn ($key) => in_array($key, $propertyNames),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** @return array<mixed> */
    private static function allExamples(): array
    {
        if (
            (new ReflectionClass(static::class))->implementsInterface(HasPropertyExamples::class)
            && method_exists(static::class, 'examples')
        ) {
            return static::examples();
        }

        return [];
    }

    /** @param array<mixed> $arrayPropTypeMap */
    private static function generateSchemaFromPropTypeMap(array $arrayPropTypeMap = []): Type\ObjectType
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
            $properties        = [];
            $defaultProperties = self::defaultProperties();
            $examplesPerProperty = self::allExamples();

            foreach (self::$__propTypeMap as $propertyName => [$type, $isScalar, $isNullable]) {
                $properties[$propertyName] = $property = self::baseProperty(
                    $propertyName,
                    $type,
                    $isScalar,
                    $isNullable,
                );

                if (! $property instanceof AnnotatedType) {
                    continue;
                }

                $reflectionProperty = (new ReflectionClass(static::class))->getProperty($propertyName);
                $description = DocBlockFactory::summaryAndDescription($reflectionProperty);
                $property = $description
                    ? $property->describedAs($description)
                    : $property;

                $examples = self::propertyExamples($examplesPerProperty, $propertyName, $reflectionProperty);
                $property = $property->withExamples(...$examples);

                try {
                    $property = $property->withDefault(self::propertyDefault($defaultProperties, $propertyName));
                } catch (Throwable) {
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

        $schema = $type === ImmutableRecord::PHP_TYPE_ARRAY
            ? self::schemaFromArrayProperty($propertyName)
            : self::getTypeFromClass($type);

        if (! $isNullable) {
            return $schema;
        }

        return JsonSchema::nullOr($schema);
    }

    private static function schemaFromArrayProperty(string $propertyName): Type
    {
        $arrayPropItemTypeMap = self::getArrayPropItemTypeMapFromMethodOrCache();
        if (! array_key_exists($propertyName, $arrayPropItemTypeMap)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Missing array item type in array property map. ' .
                    'Please provide an array item type for property %s.',
                    $propertyName,
                ),
            );
        }

        $arrayItemType = $arrayPropItemTypeMap[$propertyName];

        if (self::isScalarType($arrayItemType)) {
            return JsonSchema::array(JsonSchema::schemaFromScalarPhpType($arrayItemType, false));
        }

        if ($arrayItemType === ImmutableRecord::PHP_TYPE_ARRAY) {
            throw new InvalidArgumentException(
                sprintf(
                    "Array item type of property %s must not be 'array', " .
                    'only a scalar type or an existing class can be used as array item type.',
                    $propertyName,
                ),
            );
        }

        return JsonSchema::array(self::getTypeFromClass($arrayItemType));
    }

    /**
     * @param array<string, mixed> $examplesPerProperty
     *
     * @return array<mixed>
     */
    private static function propertyExamples(
        array $examplesPerProperty,
        string $propertyName,
        ReflectionProperty $reflectionProperty,
    ): array {
        $propertyExamples = [];

        if ($examplesPerProperty[$propertyName] ?? false) {
            $example = $examplesPerProperty[$propertyName];
            $propertyExamples[] = $example instanceof ValueObject
                ? $example->toValue()
                : $example;
        }

        return array_merge(
            $propertyExamples,
            DocBlockFactory::examples($reflectionProperty),
        );
    }

    /** @param array<string, mixed> $defaultProperties */
    public static function propertyDefault(array $defaultProperties, string $propertyName): mixed
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
        return TypeDetector::getTypeFromClass($classOrType, self::__allowNestedSchema());
    }

    /** @return array<string, mixed> */
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
    public static function fromArrayWithDefaultMaxValues(array $nativeData)
    {
        self::$__useMaxValues = true;
        $nativeData = array_merge(self::maxValues(), $nativeData);

        return self::fromArray($nativeData);
    }

    /** @return array<string, mixed> */
    private static function maxValues(): array
    {
        return [];
    }

    /** @return array<mixed> */
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
        assert(is_array(self::$__propTypeMap));

        // phpcs:disable Squiz.NamingConventions.ValidVariableName.NotCamelCaps
        foreach (self::$__propTypeMap as $key => [$type, $isNative, $isNullable]) {
            if ($isNative || isset($this->{$key})) {
                continue;
            }

            try {
                $reflectionType = new ReflectionClass($type);
            } catch (ReflectionException) {
                continue;
            }

            if (! $reflectionType->implementsInterface(ImmutableRecord::class)) {
                continue;
            }

            $this->initProperty($key, $type, $isNullable);
        }

        self::$__useMaxValues = false;
    }

    /** @param class-string<ImmutableRecord> $type */
    public function initProperty(string $key, string $type, bool $isNullable): void
    {
        try {
            $this->{$key} = self::$__useMaxValues && method_exists($type, 'fromArrayWithDefaultMaxValues')
                ? $type::fromArrayWithDefaultMaxValues([])
                : $type::fromArray([]);
        } catch (InvalidArgumentException $exception) {
            if ($isNullable) {
                $this->{$key} = null;
            } elseif (! str_starts_with($exception->getMessage(), 'Missing record data for key')) {
                throw $exception;
            }
        }
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    public function toYaml(): string
    {
        return Yaml::dump($this->toArray());
    }

    /**
     * @var ObjectType
     * phpcs:disable
     */
    private static $__schema;
}

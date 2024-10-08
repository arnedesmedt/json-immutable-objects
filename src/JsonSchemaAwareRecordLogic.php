<?php

//phpcs:disable Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps


declare(strict_types=1);

namespace ADS\JsonImmutableObjects;

use ADS\Util\ArrayUtil;
use ADS\Util\ScalarUtil;
use ADS\Util\ValueObjectUtil;
use EventEngine\Data\ImmutableRecord;
use EventEngine\Data\SpecialKeySupport;
use EventEngine\JsonSchema\AnnotatedType;
use EventEngine\JsonSchema\JsonSchema;
use EventEngine\JsonSchema\Type;
use EventEngine\JsonSchema\Type\ObjectType;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

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
use function json_decode;
use function json_encode;
use function method_exists;
use function sprintf;
use function str_starts_with;

use const ARRAY_FILTER_USE_KEY;
use const JSON_THROW_ON_ERROR;

trait JsonSchemaAwareRecordLogic
{
    use \EventEngine\JsonSchema\JsonSchemaAwareRecordLogic;
    use ImmutableRecordLogic {
        ImmutableRecordLogic::fromType insteadof \EventEngine\JsonSchema\JsonSchemaAwareRecordLogic;
        ImmutableRecordLogic::voTypeToNative insteadof \EventEngine\JsonSchema\JsonSchemaAwareRecordLogic;
        ImmutableRecordLogic::setRecordData as parentSetRecordData;
        ImmutableRecordLogic::setNativeData as parentSetNativeData;
        ImmutableRecordLogic::buildPropTypeMap as parentImmutableRecordBuildPropTypeMap;
    }

    // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore
    private static bool $__useMaxValues = false;

    /**
     * Convert value objects to native data
     * Filter out the not allowed properties.
     * Convert the keys to camel case.
     * And set the default properties.
     *
     * @param array<string, mixed> $nativeData
     */
    private function setNativeData(array $nativeData): void
    {
        assert(is_array(self::$__propTypeMap));

        $nativeData = array_map(
            static fn ($value) => ValueObjectUtil::toScalar($value) ?? $value,
            $nativeData,
        );

        // Filter not allowed properties.
        $filteredNativeData = array_intersect_key(
            $this->convertKeys($nativeData),
            self::$__propTypeMap,
        );

        $this->parentSetNativeData($filteredNativeData);
        $this->addDefaultProperties();
    }

    /**
     * Filter out the not allowed properties
     * Convert the keys to camel case.
     * And set the default properties.
     *
     * @param array<string, mixed> $recordData
     */
    private function setRecordData(array $recordData): void
    {
        assert(is_array(self::$__propTypeMap));

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
                throw new LogicException(
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

    /**
     * Combine the __defaultProperties method with the php properties that have a default value.
     *
     * @return array<string, mixed>
     */
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

            foreach (self::$__propTypeMap as $propertyName => [$type, $isScalar, $isNullable, $isSensitive]) {
                $properties[$propertyName] = $property = self::baseProperty(
                    $propertyName,
                    $type,
                    $isScalar,
                    $isNullable,
                    $isSensitive,
                );

                if (! $property instanceof AnnotatedType) {
                    continue;
                }

                $reflectionProperty = (new ReflectionClass(static::class))->getProperty($propertyName);
                $property = self::addPropertyDescription($property, $reflectionProperty);
                $property = self::addPropertyExamples($property, $reflectionProperty, $examplesPerProperty);
                $property = self::addPropertyDefaults($property, $defaultProperties, $propertyName);

                $properties[$propertyName] = $property;
            }

            $optionalProperties = array_flip(self::__optionalProperties());
            $propertiesWithoutOptional = array_diff_key($properties, $optionalProperties);
            $optionalProperties = array_intersect_key($properties, $optionalProperties);

            self::$__schema = JsonSchema::object($propertiesWithoutOptional, $optionalProperties);
        }

        return self::$__schema;
    }

    private static function baseProperty(
        string $propertyName,
        string $type,
        bool $isScalar,
        bool $isNullable,
        bool $isSensitive,
    ): Type {
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

    private static function addPropertyDescription(
        AnnotatedType $property,
        ReflectionProperty $reflectionProperty,
    ): AnnotatedType {
        $description = DocBlockFactory::summaryAndDescription($reflectionProperty);

        return $description
            ? $property->describedAs($description)
            : $property;
    }

    /** @param array<string, mixed> $examplesPerProperty */
    private static function addPropertyExamples(
        AnnotatedType $property,
        ReflectionProperty $reflectionProperty,
        array $examplesPerProperty,
    ): AnnotatedType {
        $propertyExamples = [];

        $propertyName = $reflectionProperty->getName();
        if ($examplesPerProperty[$propertyName] ?? false) {
            $example = $examplesPerProperty[$propertyName];
            $propertyExamples[] = ValueObjectUtil::toScalar($example) ?? $example;
        }

        $propertyExamples = [...$propertyExamples, ...DocBlockFactory::examples($reflectionProperty)];

        if (! empty($propertyExamples)) {
            $property = $property->withExamples(...$propertyExamples);
        }

        return $property;
    }

    /** @param array<string, mixed> $defaultProperties */
    private static function addPropertyDefaults(
        AnnotatedType $property,
        array $defaultProperties,
        string $propertyName,
    ): AnnotatedType {
        if (! isset($defaultProperties[$propertyName])) {
            return $property;
        }

        return $property->withDefault(ScalarUtil::toScalar($defaultProperties[$propertyName]));
    }

    private static function getTypeFromClass(string $classOrType): Type
    {
        return self::__allowNestedSchema()
            ? TypeDetector::typeFromClass($classOrType)
            : TypeDetector::typeFromClassAsReference($classOrType);
    }

    /** @return array<string, mixed> */
    public static function __defaultProperties(): array
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

    /**
     * Unset use max values.
     *
     * @return array<mixed>
     */
    private static function buildPropTypeMap(): array
    {
        $propTypeMap = self::parentImmutableRecordBuildPropTypeMap();

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

    public static function create(): self
    {
        return self::fromArray([]);
    }

    public static function fromJson(string $json): self
    {
        $nativeData = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($nativeData)) {
            throw new RuntimeException('Invalid JSON data.');
        }

        return self::fromArray($nativeData);
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    public static function fromYaml(string $yaml): self
    {
        $nativeData = Yaml::parse($yaml);

        if (! is_array($nativeData)) {
            throw new RuntimeException('Invalid Yaml data.');
        }

        return self::fromArray($nativeData);
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

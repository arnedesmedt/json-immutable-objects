<?php

declare(strict_types=1);

namespace ADS\JsonImmutableObjects\Polymorphism;

use ADS\JsonImmutableObjects\JsonSchemaAwareRecordLogic;
use EventEngine\Data\ImmutableRecord;
use EventEngine\JsonSchema\JsonSchema;
use EventEngine\JsonSchema\JsonSchemaAwareRecord;
use EventEngine\JsonSchema\Type\StringType;
use EventEngine\Schema\TypeSchema;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;

use function array_combine;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function class_exists;
use function class_implements;
use function in_array;
use function is_object;
use function is_string;
use function method_exists;
use function sprintf;

/**
 * @method static string propertyName()
 * @method static array jsonSchemaAwareRecords()
 */
trait DiscriminatorLogic
{
    use JsonSchemaAwareRecordLogic {
        fromRecordData as parentFromRecordData;
        with as withParent;
    }

    private JsonSchemaAwareRecord $value;

    public function value(): JsonSchemaAwareRecord
    {
        return $this->value;
    }

    /** @inheritDoc */
    public static function mapping(): array
    {
        $keys = array_map(
            static fn (string $model) => (new ReflectionClass($model))->getShortName(),
            static::jsonSchemaAwareRecords(),
        );

        /** @var array<string, class-string<JsonSchemaAwareRecord>> $mapping */
        $mapping = array_combine($keys, static::jsonSchemaAwareRecords());

        return $mapping;
    }

    /** @inheritDoc */
    public static function fromRecordData(array $recordData): self
    {
        $propertyValue        = self::propertyValue($recordData);
        $immutableRecordClass = self::immutableRecordClass($propertyValue);

        return self::parentFromRecordData(['value' => $immutableRecordClass::fromRecordData($recordData)]);
    }

    /** @inheritDoc */
    public static function fromArray(array $nativeData): self
    {
        $propertyValue        = self::propertyValue($nativeData);
        $immutableRecordClass = self::immutableRecordClass($propertyValue);

        return self::parentFromRecordData(['value' => $immutableRecordClass::fromArray($nativeData)]);
    }

    /** @param array<string, mixed> $data */
    private static function propertyValue(array $data): string
    {
        if (! isset($data[static::propertyName()])) {
            throw new InvalidArgumentException(
                sprintf(
                    'No discriminator property \'%s\' found to generate \'%s\'.',
                    static::propertyName(),
                    static::class,
                ),
            );
        }

        $propertyValue = $data[static::propertyName()];

        if (is_string($propertyValue)) {
            return $propertyValue;
        }

        if (is_object($propertyValue) && method_exists($propertyValue, 'toString')) {
            return $propertyValue->toString();
        }

        throw new LogicException(
            sprintf(
                'Property \'%s\' needs to be a string as a discriminator for \'%s\'.',
                static::propertyName(),
                static::class,
            ),
        );
    }

    /** @return class-string<JsonSchemaAwareRecord> */
    private static function immutableRecordClass(string $propertyValue): string
    {
        /** @var array<string, class-string<JsonSchemaAwareRecord>> $mapping */
        $mapping = static::mapping();

        if (! isset($mapping[$propertyValue])) {
            throw new InvalidArgumentException(
                sprintf(
                    'Discriminator value \'%s\' is not valid for \'%s\'.',
                    $propertyValue,
                    static::class,
                ),
            );
        }

        $class = $mapping[$propertyValue];

        if (! class_exists($class)) {
            throw new LogicException(
                sprintf(
                    'Class \'%s\' doesn\'t exists as oneOf model for discriminator \'%s\'.',
                    $class,
                    static::class,
                ),
            );
        }

        $interfaces = class_implements($class);

        if (! $interfaces || ! in_array(JsonSchemaAwareRecord::class, $interfaces)) {
            throw new LogicException(
                sprintf(
                    'Class \'%s\' should be an implementation of \'%s\' ' .
                    'to be a oneOf model for discriminator \'%s\'.',
                    $class,
                    JsonSchemaAwareRecord::class,
                    static::class,
                ),
            );
        }

        return $mapping[$propertyValue];
    }

    /** @inheritDoc */
    public function with(array $recordData): self
    {
        if (isset($recordData[static::propertyName()])) {
            throw new LogicException(
                sprintf(
                    'Can\'t set the discriminator property as a key in the with function for \'%s\'.',
                    static::class,
                ),
            );
        }

        return $this->withParent(['value' => $this->value->with($recordData)]);
    }

    /** @inheritDoc */
    public function toArray(): array
    {
        return $this->value->toArray();
    }

    public function equals(ImmutableRecord|Discriminator $other): bool
    {
        return $this->value->equals($other instanceof Discriminator ? $other->value() : $other);
    }

    public static function __schema(): TypeSchema
    {
        return JsonSchema::union(
            ...array_values(
                array_filter(
                    array_map(
                        static function (string $discriminatorValue, string $class) {
                            if (! method_exists($class, '__schema')) {
                                return null;
                            }

                            $schema = $class::__schema();

                            return $schema->withMergedRequiredProps(
                                [
                                    static::propertyName() => new StringType(
                                        [
                                            StringType::ENUM => [$discriminatorValue],
                                        ],
                                    ),
                                ],
                            );
                        },
                        array_keys(static::mapping()),
                        static::mapping(),
                    ),
                ),
            ),
        );
    }
}

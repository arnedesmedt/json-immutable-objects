<?php

//phpcs:disable Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps


declare(strict_types=1);

namespace ADS\JsonImmutableObjects;

use ADS\JsonImmutableObjects\Attribute\SensitiveData;
use ADS\JsonImmutableObjects\Encryption\EncryptDecryptService;
use ADS\ValueObjects\SensitiveValue;
use EventEngine\Data\ImmutableRecord;
use EventEngine\Data\SpecialKeySupport;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

use function array_key_exists;
use function array_map;
use function array_push;
use function class_exists;
use function gettype;
use function method_exists;
use function sprintf;

/** @SuppressWarnings(PHPMD) */
trait ImmutableRecordLogic
{
    use \EventEngine\Data\ImmutableRecordLogic {
        fromArray as parentFromArray;
        setNativeData as baseSetNativeData;
        voTypeToNative as parentVoTypeToNative;
        fromType as parentFromType;
        buildPropTypeMap as parentBuildPropTypeMap;
    }

    // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore
    private static bool $__encryptedSensitiveData = false;

    /**
     * Unset __encryptedSensitiveData and add extra bool to prop type map for sensitive parameters.
     *
     * @return array<mixed>
     */
    private static function buildPropTypeMap(): array
    {
        $propTypeMap = self::parentBuildPropTypeMap();
        $reflectionClass = new ReflectionClass(self::class);

        foreach ($propTypeMap as $key => &$value) {
            $reflectionProperty = $reflectionClass->getProperty($key);
            $sensitiveDataAttributes = $reflectionProperty->getAttributes(SensitiveData::class);

            if (! empty($sensitiveDataAttributes)) {
                array_push($value, true); // isSensitive = true
                continue;
            }

            $type = $reflectionProperty->getType();

            if (! $type instanceof ReflectionNamedType) {
                array_push($value, false); // isSensitive = false
                continue;
            }

            $typeClass = $type->getName();

            array_push(
                $value,
                class_exists($typeClass)
                && (new ReflectionClass($typeClass))->implementsInterface(SensitiveValue::class),
            );
        }

        unset($propTypeMap['__encryptedSensitiveData']);

        return $propTypeMap;
    }

    /**
     * Decrypt sensitive data before setting the native data.
     *
     * @param array<string, mixed> $nativeData
     */
    private function setNativeData(array $nativeData): void
    {
        if (! self::$__encryptedSensitiveData) {
            $this->baseSetNativeData($nativeData);

            return;
        }

        $recordData = [];
        $arrayPropItemTypeMap = self::getArrayPropItemTypeMapFromMethodOrCache();

        foreach ($nativeData as $key => $val) {
            $specialKey = $key;

            if ($this instanceof SpecialKeySupport) {
                $specialKey = $this->convertKeyForRecord($key);
            }

            if (! isset(self::$__propTypeMap[$specialKey])) {
                throw new InvalidArgumentException(sprintf(
                    'Invalid property passed to Record %s. Got property with key ' . $specialKey,
                    static::class,
                ));
            }

            [$type, $isNative, $isNullable, $isSensitive] = self::$__propTypeMap[$specialKey];

            if ($val === null) {
                if (! $isNullable) {
                    throw new RuntimeException(
                        sprintf(
                            'Got null for non nullable property %s of Record %s',
                            $specialKey,
                            static::class,
                        ),
                    );
                }

                $recordData[$key] = null;
                continue;
            }

            if (gettype($val) === 'string' && $isSensitive) {
                $val = EncryptDecryptService::decrypt($val);
            }

            switch ($type) {
                case ImmutableRecord::PHP_TYPE_STRING:
                case ImmutableRecord::PHP_TYPE_INT:
                case ImmutableRecord::PHP_TYPE_FLOAT:
                case ImmutableRecord::PHP_TYPE_BOOL:
                    $recordData[$key] = $val;
                    break;
                case ImmutableRecord::PHP_TYPE_ARRAY:
                    if (
                        array_key_exists($specialKey, $arrayPropItemTypeMap)
                        && ! self::isScalarType($arrayPropItemTypeMap[$specialKey])
                    ) {
                        $recordData[$key] = array_map(function ($item) use ($specialKey, &$arrayPropItemTypeMap) {
                            return $this->fromType($item, $arrayPropItemTypeMap[$specialKey]);
                        }, $val);
                    } else {
                        $recordData[$key] = $val;
                    }

                    break;
                default:
                    $recordData[$key] = $this->fromType($val, $type);
            }
        }

        $this->setRecordData($recordData);
    }

    private function fromType(mixed $value, string $type): mixed
    {
        if (self::$__encryptedSensitiveData && gettype($value) === 'array' && class_exists($type)) {
            return $type::fromEncryptedSensitiveData($value);
        }

        return $this->parentFromType($value, $type);
    }

    /**
     * Overwritten to convert value objects to native data
     * before sending them to the parent fromArray method.
     *
     * @param array<mixed> $nativeData
     *
     * @return self
     *
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.ReturnTypeHint.MissingNativeTypeHint
     */
    public static function fromEncryptedSensitiveData(array $nativeData)
    {
        self::$__encryptedSensitiveData = true;

        $immutable = self::parentFromArray($nativeData);

        self::$__encryptedSensitiveData = false;

        return $immutable;
    }

    /** @return array<string, mixed> */
    public function toSensitiveEncryptedArray(): array
    {
        self::$__encryptedSensitiveData = true;

        $nativeData = [];
        $arrayPropItemTypeMap = self::getArrayPropItemTypeMapFromMethodOrCache();

        foreach (self::$__propTypeMap as $key => [$type, $isNative, $isNullable, $isSensitive]) {
            $specialKey = $key;

            if ($this instanceof SpecialKeySupport) {
                $specialKey = $this->convertKeyForArray($key);
            }

            switch ($type) {
                case ImmutableRecord::PHP_TYPE_STRING:
                case ImmutableRecord::PHP_TYPE_INT:
                case ImmutableRecord::PHP_TYPE_FLOAT:
                case ImmutableRecord::PHP_TYPE_BOOL:
                case ImmutableRecord::PHP_TYPE_ARRAY:
                    if (
                        array_key_exists($key, $arrayPropItemTypeMap)
                        && ! self::isScalarType($arrayPropItemTypeMap[$key])
                    ) {
                        if ($isNullable && $this->{$key} === null) {
                            $nativeData[$specialKey] = null;
                            continue 2;
                        }

                        $nativeData[$specialKey] = array_map(function ($item) use ($key, &$arrayPropItemTypeMap) {
                            return $this->voTypeToNative($item, $key, $arrayPropItemTypeMap[$key]);
                        }, $this->{$key});
                    } else {
                        $nativeData[$specialKey] = $this->{$key};
                    }

                    break;
                default:
                    if ($isNullable && (! isset($this->{$key}))) {
                        $nativeData[$specialKey] = null;
                        continue 2;
                    }

                    $nativeData[$specialKey] = $this->voTypeToNative($this->{$key}, $key, $type);
            }

            if (! $isSensitive) {
                continue;
            }

            if (gettype($nativeData[$specialKey]) !== 'string') {
                throw new RuntimeException(
                    sprintf('Sensitive data must be a string. Property \'%s\' is not a string.', $key),
                );
            }

            $nativeData[$specialKey] = EncryptDecryptService::encrypt($nativeData[$specialKey]);
        }

        self::$__encryptedSensitiveData = false;

        return $nativeData;
    }

    private function voTypeToNative(mixed $value, string $key, string $type): mixed
    {
        if (self::$__encryptedSensitiveData && method_exists($value, 'toSensitiveEncryptedArray')) {
            return $value->toSensitiveEncryptedArray();
        }

        return $this->parentVoTypeToNative($value, $key, $type);
    }
}

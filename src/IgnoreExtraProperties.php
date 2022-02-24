<?php

declare(strict_types=1);

namespace ADS\JsonImmutableObjects;

use function array_intersect_key;

trait IgnoreExtraProperties
{
    use JsonSchemaAwareRecordLogic {
        fromArray as grandParentFromArray;
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
        return self::grandParentFromArray(
            array_intersect_key($nativeData, self::buildPropTypeMap()),
            $useMaxValuesAsDefaults
        );
    }
}

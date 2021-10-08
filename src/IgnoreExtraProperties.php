<?php

declare(strict_types=1);

namespace ADS\JsonImmutableObjects;

use function array_intersect_key;

trait IgnoreExtraProperties
{
    use DefaultImmutableProperties {
        fromArray as parentFromArray;
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
        return self::parentFromArray(
            array_intersect_key($nativeData, self::buildPropTypeMap()),
            $useMaxValuesAsDefaults
        );
    }
}

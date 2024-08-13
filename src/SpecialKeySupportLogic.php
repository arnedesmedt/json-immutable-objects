<?php

declare(strict_types=1);

namespace ADS\JsonImmutableObjects;

use function array_flip;

trait SpecialKeySupportLogic
{
    /** @return array<string, string> */
    abstract public function keyMapping(): array;

    public function convertKeyForRecord(string $key): string
    {
        return $this->keyMapping()[$key] ?? $key;
    }

    public function convertKeyForArray(string $key): string
    {
        return array_flip($this->keyMapping())[$key] ?? $key;
    }
}

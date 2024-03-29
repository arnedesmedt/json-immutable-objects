<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Core\ValueObject\PhpVersion;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Set\ValueObject\LevelSetList;

return static function (RectorConfig $rectorConfig): void {
    // get parameters
    $rectorConfig->paths(
        [
            __DIR__ . '/src',
            __DIR__ . '/tests',
        ],
    );
    $rectorConfig->phpVersion(PhpVersion::PHP_82);
    $rectorConfig->import(LevelSetList::UP_TO_PHP_82);

    $rectorConfig->skip(
        [
            StringClassNameToClassConstantRector::class => [
                __DIR__ . '/src/TypeDetector.php',
            ],
            ReadOnlyPropertyRector::class => [
                __DIR__ . '/tests/Object',
            ],
        ],
    );
};

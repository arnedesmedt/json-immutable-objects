<?php

declare(strict_types=1);

namespace ADS\JsonImmutableObjects;

use ADS\Util\StringUtil;
use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock\Tag;
use phpDocumentor\Reflection\DocBlock\Tags\Generic;
use phpDocumentor\Reflection\DocBlockFactory as PhpDocumentorDocBlockFactory;
use Throwable;

use function array_filter;
use function array_map;
use function assert;
use function implode;

class DocBlockFactory
{
    public static function summaryAndDescription(object $object, string $separator = '<br/>'): string|null
    {
        $docBlock = self::docBlock($object);

        if ($docBlock === null) {
            return null;
        }

        $summary     = $docBlock->getSummary();
        $description = $docBlock->getDescription()->render();

        if (empty($summary) && empty($description)) {
            return null;
        }

        return implode(
            $separator,
            array_filter(
                [
                    $docBlock->getSummary(),
                    $docBlock->getDescription()->render(),
                ],
            ),
        );
    }

    /** @return array<int, mixed> */
    public static function examples(object $object): array
    {
        $docBlock = self::docBlock($object);

        if ($docBlock === null) {
            return [];
        }

        $examples = $docBlock->getTagsByName('example');

        $nonEmptyExamples = array_filter(
            $examples,
            static function (Tag $example) {
                assert($example instanceof Generic);

                return $example->getDescription() !== null;
            },
        );

        return array_map(
            static function (Tag $example) {
                assert($example instanceof Generic);
                /** @var DocBlock\Description $description */
                $description = $example->getDescription();

                return StringUtil::castFromString($description->render());
            },
            $nonEmptyExamples,
        );
    }

    private static function docBlock(object $object): DocBlock|null
    {
        try {
            return PhpDocumentorDocBlockFactory::createInstance()->create($object);
        } catch (Throwable) {
            return null;
        }
    }
}

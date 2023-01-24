<?php

declare(strict_types=1);

namespace ADS\JsonImmutableObjects\Tests\Object;

use ADS\JsonImmutableObjects\JsonSchemaAwareRecordLogic;
use EventEngine\JsonSchema\JsonSchemaAwareRecord;

class OneOfWithDiscriminatorTwo implements JsonSchemaAwareRecord
{
    use JsonSchemaAwareRecordLogic;

    private TypeVO $type;
    private string $other;
}

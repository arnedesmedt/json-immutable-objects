<?php

declare(strict_types=1);

namespace ADS\JsonImmutableObjects\Tests\Object;

use ADS\JsonImmutableObjects\JsonSchemaAwareRecordLogic;
use EventEngine\JsonSchema\JsonSchemaAwareRecord;

class OneOfWithDiscriminatorOne implements JsonSchemaAwareRecord
{
    use JsonSchemaAwareRecordLogic;

    private string $type;
    private int $value;

    public function value(): int
    {
        return $this->value;
    }
}

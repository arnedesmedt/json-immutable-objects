<?php

declare(strict_types=1);

namespace ADS\JsonImmutableObjects\Exception\EncryptDecrypt;

use Exception;

final class DecryptionFailedException extends Exception
{
    public function __construct()
    {
        parent::__construct('Decryption failed.');
    }
}

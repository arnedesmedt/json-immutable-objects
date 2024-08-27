<?php

declare(strict_types=1);

namespace ADS\JsonImmutableObjects\Exception\EncryptDecrypt;

use ADS\JsonImmutableObjects\Encryption\EncryptDecryptService;
use Exception;

use function sprintf;

use const PHP_EOL;

abstract class InvalidSecretKeyException extends Exception
{
    public function __construct(string $message)
    {
        parent::__construct(
            sprintf(
                '%s' . PHP_EOL .
                'You can use the following script to generate a key with the correct length:' . PHP_EOL .
                'php -r "echo bin2hex(random_bytes(%d));"',
                $message,
                EncryptDecryptService::ENVIRONMENT_SECRET_KEY_REQUIRED_BYTES_LENGTH,
            ),
        );
    }
}

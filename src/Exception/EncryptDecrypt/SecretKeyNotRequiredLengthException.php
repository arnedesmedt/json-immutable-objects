<?php

declare(strict_types=1);

namespace ADS\JsonImmutableObjects\Exception\EncryptDecrypt;

use ADS\JsonImmutableObjects\Encryption\EncryptDecryptService;

use function sprintf;

final class SecretKeyNotRequiredLengthException extends InvalidSecretKeyException
{
    public function __construct(int $currentLength)
    {
        parent::__construct(
            sprintf(
                'The secret key doesn\'t have the correct length of %d bytes, it is only %d bytes long.',
                EncryptDecryptService::ENVIRONMENT_SECRET_KEY_REQUIRED_BYTES_LENGTH,
                $currentLength,
            ),
        );
    }
}

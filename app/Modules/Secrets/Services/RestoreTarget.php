<?php

declare(strict_types=1);

namespace App\Modules\Secrets\Services;

/**
 * Where a restored plaintext should land. The vault writes the decrypted bytes;
 * how/where is the target's concern.
 */
final class RestoreTarget
{
    public const TYPE_ENV_FILE = 'env_file';

    public const TYPE_STDOUT = 'stdout';

    private function __construct(
        public readonly string $type,
        public readonly ?string $path = null,
        public readonly bool $force = false,
    ) {}

    public static function envFile(string $path, bool $force = false): self
    {
        return new self(self::TYPE_ENV_FILE, $path, $force);
    }

    public static function stdout(): self
    {
        return new self(self::TYPE_STDOUT);
    }
}

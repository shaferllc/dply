<?php

declare(strict_types=1);

namespace App\Services\Secrets;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * The only crypto seam. Shells out to the pinned `age` binary — identical to
 * what the bash guards use — so ciphertext is interchangeable between the PHP
 * and shell paths.
 *
 * Encryption needs only the PUBLIC recipients file (safe on every box).
 * Decryption needs the OFFLINE private identity and is expected to be
 * impossible in normal prod — that is the asymmetric guarantee.
 */
final class AgeEncryptor
{
    public function __construct(
        private readonly string $ageBin,
        private readonly string $recipientsPath,
        private readonly ?string $identityPath = null,
    ) {}

    public function encrypt(string $plaintext): string
    {
        if (! is_file($this->recipientsPath)) {
            throw new RuntimeException("age recipients file not found: {$this->recipientsPath}");
        }

        $result = Process::input($plaintext)
            ->timeout(120)
            ->run([$this->ageBin, '-e', '-R', $this->recipientsPath]);

        if (! $result->successful()) {
            throw new RuntimeException('age encrypt failed: '.trim($result->errorOutput()));
        }

        return $result->output();
    }

    public function decrypt(string $ciphertext): string
    {
        if ($this->identityPath === null || ! is_file($this->identityPath)) {
            throw new RuntimeException(
                'age identity not available — restore/verify requires the offline private key (SECRET_VAULT_IDENTITY_PATH).'
            );
        }

        $result = Process::input($ciphertext)
            ->timeout(120)
            ->run([$this->ageBin, '-d', '-i', $this->identityPath]);

        if (! $result->successful()) {
            throw new RuntimeException('age decrypt failed: '.trim($result->errorOutput()));
        }

        return $result->output();
    }

    public function canDecrypt(): bool
    {
        return $this->identityPath !== null && is_file($this->identityPath);
    }
}

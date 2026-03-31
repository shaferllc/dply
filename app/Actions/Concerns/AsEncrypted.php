<?php

namespace App\Actions\Concerns;

use Illuminate\Support\Facades\Crypt;

/**
 * Automatically encrypts/decrypts sensitive data.
 *
 * @example
 * // Action class:
 * class StoreSensitiveData extends Actions
 * {
 *     use AsEncrypted;
 *
 *     public function handle(array $data): void
 *     {
 *         // Data is automatically encrypted before storage
 *         SensitiveData::create($this->encrypt($data));
 *     }
 *
 *     // Optional: specify which fields to encrypt
 *     public function getEncryptedFields(): array
 *     {
 *         return ['ssn', 'credit_card', 'password'];
 *     }
 * }
 *
 * // Usage:
 * StoreSensitiveData::run(['ssn' => '123-45-6789']);
 * // SSN is automatically encrypted
 */
trait AsEncrypted
{
    protected function encrypt(mixed $data): mixed
    {
        if (is_array($data)) {
            return $this->encryptArray($data);
        }

        if (is_string($data)) {
            return Crypt::encryptString($data);
        }

        return $data;
    }

    protected function decrypt(mixed $data): mixed
    {
        if (is_array($data)) {
            return $this->decryptArray($data);
        }

        if (is_string($data)) {
            try {
                return Crypt::decryptString($data);
            } catch (\Throwable $e) {
                return $data; // Return as-is if decryption fails
            }
        }

        return $data;
    }

    protected function encryptArray(array $data): array
    {
        $fields = $this->getEncryptedFields();

        foreach ($data as $key => $value) {
            if (in_array($key, $fields)) {
                $data[$key] = is_string($value) ? Crypt::encryptString($value) : $value;
            } elseif (is_array($value)) {
                $data[$key] = $this->encryptArray($value);
            }
        }

        return $data;
    }

    protected function decryptArray(array $data): array
    {
        $fields = $this->getEncryptedFields();

        foreach ($data as $key => $value) {
            if (in_array($key, $fields) && is_string($value)) {
                try {
                    $data[$key] = Crypt::decryptString($value);
                } catch (\Throwable $e) {
                    // Keep encrypted if decryption fails
                }
            } elseif (is_array($value)) {
                $data[$key] = $this->decryptArray($value);
            }
        }

        return $data;
    }

    protected function getEncryptedFields(): array
    {
        return $this->hasMethod('getEncryptedFields')
            ? $this->callMethod('getEncryptedFields')
            : ['password', 'ssn', 'credit_card', 'api_key', 'secret'];
    }
}

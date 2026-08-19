<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\ProviderCredential;
use App\Support\Providers\ProviderAuthFailure;

/**
 * The region/size catalogs swallow provider exceptions so the modal can
 * still render. Remember the last failure so the UI can say "token
 * rejected" instead of a generic empty-catalog line.
 */
final class ManagedDatabaseCatalogFailure
{
    private static ?string $message = null;

    private static ?string $provider = null;

    private static ?ProviderCredential $workingCredential = null;

    public static function clear(): void
    {
        self::$message = null;
        self::$provider = null;
        self::$workingCredential = null;
    }

    public static function remember(\Throwable $exception, ?string $provider = null): void
    {
        self::$message = $exception->getMessage();
        if (is_string($provider) && $provider !== '') {
            self::$provider = $provider;
        }
    }

    public static function rememberWorkingCredential(ProviderCredential $credential): void
    {
        self::$workingCredential = $credential;
        self::$message = null;
        self::$provider = null;
    }

    public static function workingCredential(): ?ProviderCredential
    {
        return self::$workingCredential;
    }

    public static function lastError(): ?string
    {
        return self::$message;
    }

    public static function provider(): string
    {
        return self::$provider ?? 'digitalocean';
    }

    public static function isAuthFailure(): bool
    {
        return ProviderAuthFailure::detected(self::$message);
    }

    public static function operatorMessage(): ?string
    {
        $raw = self::$message;
        if ($raw === null || $raw === '') {
            return null;
        }

        if (ProviderAuthFailure::detected($raw)) {
            return ProviderAuthFailure::message(self::provider());
        }

        return $raw;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ProductionData;

use App\Models\ProductionDataConnection;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;

class ProductionDataMirror
{
    public const SESSION_WRITE_UNLOCKED = 'production_data_write_unlocked';

    public static function available(): bool
    {
        return (bool) config('dply.production_data_mirror.enabled', false)
            && app()->environment('local');
    }

    public static function defaultBaseUrl(): string
    {
        return rtrim((string) config('dply.production_data_mirror.default_base_url', ''), '/');
    }

    public function connectionFor(?User $user): ?ProductionDataConnection
    {
        if ($user === null || ! self::available()) {
            return null;
        }

        return ProductionDataConnection::query()
            ->where('user_id', $user->id)
            ->first();
    }

    public function clientFor(ProductionDataConnection $connection): ProductionApiClient
    {
        $connection->forceFill(['last_used_at' => now()])->saveQuietly();

        return ProductionApiClient::forConnection($connection);
    }

    /**
     * @template T
     *
     * @param  callable(ProductionApiClient): T  $callback
     * @return T
     */
    public function withClient(ProductionDataConnection $connection, callable $callback): mixed
    {
        try {
            return $callback($this->clientFor($connection));
        } catch (ProductionApiException $e) {
            if ($e->isUnauthorized()) {
                $this->disconnect($connection);
            }

            throw $e;
        }
    }

    /**
     * @template T
     *
     * @param  callable(ProductionApiClient): T  $callback
     * @return T
     */
    public function remember(ProductionDataConnection $connection, string $key, callable $callback): mixed
    {
        $ttl = (int) config('dply.production_data_mirror.cache_ttl_seconds', 20);
        $cacheKey = $this->cacheKey($connection, $key);

        return Cache::remember($cacheKey, $ttl, fn () => $this->withClient($connection, $callback));
    }

    public function forget(ProductionDataConnection $connection, ?string $key = null): void
    {
        if ($key !== null) {
            Cache::forget($this->cacheKey($connection, $key));

            return;
        }

        $versionKey = $this->versionKey($connection);
        Cache::forever($versionKey, ((int) Cache::get($versionKey, 1)) + 1);
    }

    public function disconnect(ProductionDataConnection $connection): void
    {
        $this->forget($connection);
        Cache::forget($this->versionKey($connection));
        Session::forget(self::SESSION_WRITE_UNLOCKED);
        $connection->delete();
    }

    public function writesUnlocked(): bool
    {
        return (bool) Session::get(self::SESSION_WRITE_UNLOCKED, false);
    }

    public function unlockWrites(): void
    {
        Session::put(self::SESSION_WRITE_UNLOCKED, true);
    }

    public function lockWrites(): void
    {
        Session::forget(self::SESSION_WRITE_UNLOCKED);
    }

    protected function cacheKey(ProductionDataConnection $connection, string $key): string
    {
        $version = (int) Cache::get($this->versionKey($connection), 1);

        return 'production-data:'.$connection->id.':v'.$version.':'.$key;
    }

    protected function versionKey(ProductionDataConnection $connection): string
    {
        return 'production-data:'.$connection->id.':version';
    }
}

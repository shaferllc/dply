<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Models\FeaturePlatformOverride;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Pennant\Feature;
use Throwable;

/**
 * Platform-wide feature-flag override layer.
 *
 * Resolution precedence for any Pennant flag becomes:
 *   1. explicit per-org row in `features`   (highest — Pennant applies it)
 *   2. platform override here (this table)   (new — beats config for every scope)
 *   3. config/features.php (env) default     (lowest)
 *
 * The whole set is loaded once per request and memoized, so consulting it
 * from the FeatureServiceProvider resolver costs at most one query even when
 * a request checks every flag. Reads are guarded so a not-yet-migrated
 * database (fresh install / boot before migrate) resolves to config defaults
 * instead of throwing.
 */
final class PlatformFeatureDefaults
{
    /** @var array<string, bool>|null */
    private static ?array $cache = null;

    /**
     * @return array<string, bool> flag key => enabled
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            if (! Schema::hasTable('feature_platform_overrides')) {
                return self::$cache = [];
            }

            return self::$cache = FeaturePlatformOverride::query()
                ->pluck('enabled', 'name')
                ->map(fn ($value): bool => (bool) $value)
                ->all();
        } catch (Throwable) {
            return self::$cache = [];
        }
    }

    public static function get(string $flag): ?bool
    {
        return self::all()[$flag] ?? null;
    }

    public static function has(string $flag): bool
    {
        return array_key_exists($flag, self::all());
    }

    public static function set(string $flag, bool $enabled): void
    {
        $previousDefault = self::effective($flag);

        FeaturePlatformOverride::query()->updateOrCreate(
            ['name' => $flag],
            ['enabled' => $enabled],
        );

        self::applyChange($flag, $previousDefault);
    }

    public static function clear(string $flag): void
    {
        $previousDefault = self::effective($flag);

        FeaturePlatformOverride::query()->where('name', $flag)->delete();

        self::applyChange($flag, $previousDefault);
    }

    /**
     * The effective platform default for a flag: the override if present,
     * otherwise the config/env default.
     */
    public static function effective(string $flag): bool
    {
        return self::get($flag) ?? self::configDefault($flag);
    }

    /**
     * Drop the in-request memo and Pennant's resolved-value cache so the next
     * check re-resolves against the new override state.
     */
    public static function flush(): void
    {
        self::$cache = null;
        Feature::flushCache();
    }

    /**
     * Make a just-changed platform default effective across scopes.
     *
     * Pennant's database driver persists every resolved value, so those stored
     * rows would otherwise shadow the resolver (this is why the app normally
     * needs `pennant:purge`). We purge exactly the rows that must re-resolve:
     * the null-scope cache (global.* flags), plus any per-scope row that was
     * merely following the OLD default. Rows whose stored value differs from
     * the old default are genuine overrides (per-org toggles) and are left
     * intact, so an explicit per-org choice still wins.
     */
    private static function applyChange(string $flag, bool $previousDefault): void
    {
        $followingOldDefault = json_encode($previousDefault);

        DB::table('features')
            ->where('name', $flag)
            ->where(function ($query) use ($followingOldDefault): void {
                $query->where('scope', Feature::serializeScope(null))
                    ->orWhere('value', $followingOldDefault);
            })
            ->delete();

        self::flush();
    }

    private static function configDefault(string $flag): bool
    {
        [$namespace, $leaf] = array_pad(explode('.', $flag, 2), 2, '');

        return (bool) (config("features.{$namespace}.{$leaf}") ?? false);
    }
}

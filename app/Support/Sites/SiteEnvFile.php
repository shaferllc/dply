<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;

/**
 * Read and write a site's managed `.env` blob.
 *
 * Kernel, not module, and extracted for a specific reason: dply Cache attaches
 * by writing env keys, and the only implementation of that lived on
 * `ServerlessEnvironmentPreparer` in the Deploy module — which in turn wants to
 * ask the Cache module whether a function needs a cache. Depending on each
 * other made the container fail to resolve either, and because the dependency
 * was declared with a `= null` default, Laravel quietly substituted the default
 * instead of throwing: the auto-wiring compiled, passed a direct-resolution
 * test, and would have done nothing at all in production.
 *
 * A shared piece both sides need belongs in the kernel. That is the same fix
 * `tests/Unit/ModuleBoundaryTest` prints when it rejects a module→shell edge.
 */
final class SiteEnvFile
{
    /**
     * Upsert keys — existing ones replaced in place, new ones appended.
     *
     * In place rather than append-and-let-the-last-win, because the file is
     * shown to the operator in the Environment panel and a key listed twice
     * reads as a bug even when Dotenv resolves it correctly.
     *
     * @param  array<string, mixed>  $values
     */
    public static function merge(Site $site, array $values): void
    {
        $content = (string) $site->env_file_content;
        $lines = $content === '' ? [] : (preg_split('/\r\n|\r|\n/', $content) ?: []);

        foreach ($values as $key => $value) {
            $entry = $key.'='.self::quote((string) $value);
            $replaced = false;

            foreach ($lines as $index => $existing) {
                if (preg_match('/^\s*'.preg_quote((string) $key, '/').'\s*=/', (string) $existing) === 1) {
                    $lines[$index] = $entry;
                    $replaced = true;
                    break;
                }
            }

            if (! $replaced) {
                $lines[] = $entry;
            }
        }

        $site->forceFill(['env_file_content' => implode("\n", $lines)])->save();
    }

    /**
     * Remove keys entirely.
     *
     * There is no "unset" in an upsert, which is why this exists separately:
     * a detach that could only overwrite would leave a stale endpoint or a dead
     * credential behind rather than removing it.
     *
     * @param  list<string>  $keys
     */
    public static function strip(Site $site, array $keys): void
    {
        $content = (string) $site->env_file_content;

        if (trim($content) === '') {
            return;
        }

        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];

        $kept = array_values(array_filter($lines, function (string $line) use ($keys): bool {
            foreach ($keys as $key) {
                if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=/', $line) === 1) {
                    return false;
                }
            }

            return true;
        }));

        $site->forceFill(['env_file_content' => implode("\n", $kept)])->save();
    }

    /**
     * Parse the blob into a map, for predicates that need to inspect it.
     *
     * @return array<string, string>
     */
    public static function parse(string $content): array
    {
        $values = [];

        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
        }

        return $values;
    }

    /** Quote only when the value needs it, so the file stays readable. */
    private static function quote(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_.:\/+=-]+$/', $value) === 1) {
            return $value;
        }

        return '"'.str_replace('"', '\"', $value).'"';
    }
}

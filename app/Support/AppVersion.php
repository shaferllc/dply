<?php

namespace App\Support;

/**
 * The deployed app version, shown as a release DATE (derived from the deployed
 * commit) rather than a cryptic hash — the short SHA is kept alongside for
 * detail/tooltip.
 *
 * Resolution is cheap (a few small file reads, no git binary, no shell) and
 * memoised per request:
 *   - date: for an atomic deploy the app lives in `releases/<YYYYMMDDHHMMSS>`,
 *     so the release date is the folder timestamp; otherwise fall back to the
 *     mtime of `.git/HEAD` (last commit/checkout) for flat/local checkouts.
 *   - sha:  read straight from `.git/HEAD` (loose ref, packed-refs, or a
 *     detached SHA).
 */
final class AppVersion
{
    /** @var array{date: string, sha: string}|null */
    private static ?array $resolved = null;

    /** Date-based version, e.g. "2026.06.09". */
    public static function date(): string
    {
        return self::resolve()['date'];
    }

    /** Short commit SHA, e.g. "35cab86" (empty when undeterminable). */
    public static function sha(): string
    {
        return self::resolve()['sha'];
    }

    /** Combined label, e.g. "2026.06.09 · 35cab86" (date alone when no SHA). */
    public static function label(): string
    {
        $r = self::resolve();

        return $r['sha'] !== '' ? $r['date'].' · '.$r['sha'] : $r['date'];
    }

    /** @return array{date: string, sha: string} */
    public static function resolve(): array
    {
        return self::$resolved ??= self::compute();
    }

    /** @return array{date: string, sha: string} */
    private static function compute(): array
    {
        $base = realpath(base_path()) ?: base_path();

        $date = null;
        // Atomic release: .../releases/<YYYYMMDDHHMMSS> — the deploy date.
        if (preg_match('#/releases/(\d{4})(\d{2})(\d{2})\d{6}/?$#', $base, $m)) {
            $date = $m[1].'.'.$m[2].'.'.$m[3];
        }

        $sha = self::readShaFromGit($base);

        if ($date === null) {
            // Flat/local checkout: approximate the release date with the last
            // HEAD change (commit/checkout); fall back to a literal marker.
            $mtime = @filemtime($base.'/.git/HEAD');
            $date = $mtime !== false ? date('Y.m.d', $mtime) : 'dev';
        }

        return ['date' => $date, 'sha' => $sha];
    }

    private static function readShaFromGit(string $base): string
    {
        $head = @file_get_contents($base.'/.git/HEAD');
        if (! is_string($head)) {
            return '';
        }
        $head = trim($head);

        // Detached HEAD: the file is the SHA itself.
        if (preg_match('/^[0-9a-f]{7,40}$/', $head)) {
            return substr($head, 0, 7);
        }

        // Symbolic ref: resolve the loose ref, else packed-refs.
        if (preg_match('/^ref:\s*(.+)$/', $head, $m)) {
            $ref = trim($m[1]);
            $loose = @file_get_contents($base.'/.git/'.$ref);
            if (is_string($loose) && preg_match('/^[0-9a-f]{40}$/', trim($loose))) {
                return substr(trim($loose), 0, 7);
            }
            $packed = @file_get_contents($base.'/.git/packed-refs');
            if (is_string($packed) && preg_match('/^([0-9a-f]{40})\s+'.preg_quote($ref, '/').'$/m', $packed, $pm)) {
                return substr($pm[1], 0, 7);
            }
        }

        return '';
    }
}

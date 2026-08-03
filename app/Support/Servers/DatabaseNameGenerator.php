<?php

declare(strict_types=1);

namespace App\Support\Servers;

use Illuminate\Support\Str;

/**
 * Database identifier suggestions for the "provision a database" forms.
 *
 * Two flavours, mirroring the server-create Identity card:
 *   - {@see suggest()} seeds the field from the site name so the box is never
 *     blank when the modal opens (dply.io → `dply_io`);
 *   - {@see random()} is the Regenerate button — the same adjective+noun pool
 *     {@see ServerNameGenerator} draws from, re-spelled as an identifier.
 *
 * Everything funnels through {@see sanitize()}, which enforces what MySQL and
 * PostgreSQL will accept unquoted: lowercase [a-z0-9_], no leading digit, no
 * doubled/edge underscores, 64 chars max.
 */
final class DatabaseNameGenerator
{
    public const MAX_LENGTH = 64;

    /** Seed a name from the site (or any label); falls back to `app`. */
    public static function suggest(?string $seed): string
    {
        return self::sanitize((string) $seed) ?: 'app';
    }

    /**
     * A random `adjective_noun` identifier. Pass the current value as $exclude
     * so clicking Regenerate always visibly changes the field.
     */
    public static function random(?string $exclude = null): string
    {
        for ($attempt = 0; $attempt < 25; $attempt++) {
            $name = self::sanitize(ServerNameGenerator::generate());
            if ($name !== '' && $name !== self::sanitize((string) $exclude)) {
                return $name;
            }
        }

        return self::sanitize(ServerNameGenerator::generate().'_'.Str::lower(Str::random(3))) ?: 'app';
    }

    /** Coerce free text into a legal, unquoted database identifier. */
    public static function sanitize(string $value): string
    {
        $value = strtolower($value);
        $value = preg_replace('/[\s.\-]+/', '_', $value) ?? '';
        $value = preg_replace('/[^a-z0-9_]/', '', $value) ?? '';
        $value = preg_replace('/_+/', '_', $value) ?? '';
        $value = trim($value, '_');

        // A leading digit is illegal unquoted in MySQL/Postgres — prefix rather
        // than drop it, so `2024_archive` stays recognisable as `db_2024_archive`.
        if ($value !== '' && ctype_digit($value[0])) {
            $value = 'db_'.$value;
        }

        return substr($value, 0, self::MAX_LENGTH);
    }
}

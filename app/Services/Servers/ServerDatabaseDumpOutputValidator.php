<?php

namespace App\Services\Servers;

/**
 * Detects failed mysqldump / pg_dump output when stderr is merged into stdout over SSH.
 */
class ServerDatabaseDumpOutputValidator
{
    public static function looksLikeFailedDump(string $engine, string $output): bool
    {
        if ($engine === 'postgres') {
            if (preg_match('/^pg_dump:\\s/m', $output)) {
                return true;
            }
            if (preg_match('/^pg_dump:\\s*error:/mi', $output)) {
                return true;
            }
            if (str_contains($output, 'FATAL:') || str_contains($output, 'could not connect')) {
                return true;
            }

            return false;
        }

        if (preg_match('/^mysqldump:\\s/m', $output)) {
            return true;
        }
        if (preg_match('/mysqldump:\\s*(Error|Got error)/i', $output)) {
            return true;
        }
        if (str_contains($output, 'Access denied for user')) {
            return true;
        }

        return false;
    }
}

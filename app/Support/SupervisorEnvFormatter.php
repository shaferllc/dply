<?php

declare(strict_types=1);

namespace App\Support;

final class SupervisorEnvFormatter
{
    /**
     * Parse "KEY=value" lines (Bash-style, no export keyword).
     *
     * @return array<string, string>
     */
    public static function parseLines(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (! preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
                continue;
            }
            $out[$m[1]] = $m[2];
        }

        return $out;
    }

    /**
     * Supervisor INI fragment: environment=KEY="val",KEY2="val2"
     */
    public static function toIniFragment(array $env): string
    {
        if ($env === []) {
            return '';
        }
        $parts = [];
        foreach ($env as $k => $v) {
            $key = preg_replace('/[^A-Za-z0-9_]/', '', (string) $k) ?: 'VAR';
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v);
            $parts[] = $key.'="'.$escaped.'"';
        }

        return 'environment='.implode(',', $parts)."\n";
    }
}

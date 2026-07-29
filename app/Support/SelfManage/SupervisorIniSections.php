<?php

declare(strict_types=1);

namespace App\Support\SelfManage;

/**
 * Tiny INI section splitter for supervisor conf files (program/group blocks).
 * Not a full INI parser — preserves raw block text for merge/rewrite.
 */
final class SupervisorIniSections
{
    /**
     * @return array{preamble: string, sections: array<string, string>}
     *                                                                  section keys are e.g. "program:dply-horizon"
     */
    public static function parse(string $contents): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $contents) ?: [];
        $preamble = [];
        $sections = [];
        $currentKey = null;
        $currentLines = [];

        $flush = static function () use (&$sections, &$currentKey, &$currentLines): void {
            if ($currentKey === null) {
                return;
            }
            $sections[$currentKey] = rtrim(implode("\n", $currentLines))."\n";
            $currentKey = null;
            $currentLines = [];
        };

        foreach ($lines as $line) {
            if (preg_match('/^\s*\[([^\]]+)\]\s*$/', $line, $m) === 1) {
                $flush();
                $currentKey = trim($m[1]);
                $currentLines = [$line];

                continue;
            }

            if ($currentKey === null) {
                $preamble[] = $line;
            } else {
                $currentLines[] = $line;
            }
        }
        $flush();

        return [
            'preamble' => rtrim(implode("\n", $preamble)),
            'sections' => $sections,
        ];
    }

    /**
     * @param  array<string, string>  $sections
     */
    public static function render(string $preamble, array $sections): string
    {
        $parts = [];
        $preamble = trim($preamble);
        if ($preamble !== '') {
            $parts[] = $preamble;
        }
        foreach ($sections as $body) {
            $parts[] = rtrim($body);
        }

        return implode("\n\n", $parts)."\n";
    }

    /**
     * @param  array<string, string>  $sections
     * @return list<string> program names without the "program:" prefix
     */
    public static function programNames(array $sections): array
    {
        $names = [];
        foreach (array_keys($sections) as $key) {
            if (str_starts_with($key, 'program:')) {
                $names[] = substr($key, strlen('program:'));
            }
        }

        return $names;
    }
}

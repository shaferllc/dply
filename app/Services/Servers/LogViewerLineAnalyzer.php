<?php

namespace App\Services\Servers;

/**
 * Lightweight heuristics over raw log text (no parsing guarantees).
 *
 * @return array{http_5xx: int, http_4xx: int, http_2xx_3xx: int, error_keywords: int, warn_keywords: int, lines: int}
 */
class LogViewerLineAnalyzer
{
    public static function analyze(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $joined = strtolower($text);

        $http5 = 0;
        $http4 = 0;
        $http23 = 0;
        if (preg_match_all('/\b(?:HTTP\/\d\.\d\s+)?(\d{3})\b/', $text, $m)) {
            foreach ($m[1] as $code) {
                $c = (int) $code;
                if ($c >= 500) {
                    $http5++;
                } elseif ($c >= 400) {
                    $http4++;
                } elseif ($c >= 200 && $c < 400) {
                    $http23++;
                }
            }
        }

        $err = 0;
        foreach ([' error', ' fatal', 'critical', 'emerg'] as $needle) {
            $err += substr_count($joined, $needle);
        }

        $warn = substr_count($joined, 'warn');

        return [
            'http_5xx' => $http5,
            'http_4xx' => $http4,
            'http_2xx_3xx' => $http23,
            'error_keywords' => $err,
            'warn_keywords' => $warn,
            'lines' => count($lines),
        ];
    }
}

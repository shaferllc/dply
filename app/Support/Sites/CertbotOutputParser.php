<?php

declare(strict_types=1);

namespace App\Support\Sites;

use Illuminate\Support\Str;

final class CertbotOutputParser
{
    public static function failureSummary(string $output): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $output) ?: [];
        $details = [];

        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, 'DPLY_EXIT:')) {
                continue;
            }

            if (preg_match('/^(Error:|Detail:|Type:|Hint:|IMPORTANT NOTE:|\[dply\])/', $trim) === 1) {
                $details[] = $trim;
            }
        }

        if ($details === []) {
            $nonEmpty = array_values(array_filter(array_map(trim(...), $lines)));
            $details = array_slice($nonEmpty, -4);
        }

        return Str::limit(implode(' ', $details), 500);
    }
}

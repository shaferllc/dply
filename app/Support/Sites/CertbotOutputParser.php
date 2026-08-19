<?php

declare(strict_types=1);

namespace App\Support\Sites;

use Illuminate\Support\Str;

final class CertbotOutputParser
{
    /**
     * True when certbot decided the existing lineage was still good and wrote
     * nothing. It exits 0 in that case, so a naive caller records a fresh
     * install for a cert that was never touched — which then wins any
     * "most recently installed" ordering over the cert actually on disk.
     */
    public static function notYetDueForRenewal(string $output): bool
    {
        return preg_match(
            '/not\\s+yet\\s+due\\s+for\\s+renewal|keeping\\s+the\\s+existing\\s+certificate/i',
            $output,
        ) === 1;
    }

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

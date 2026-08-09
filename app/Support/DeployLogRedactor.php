<?php

namespace App\Support;

class DeployLogRedactor
{
    /**
     * Strip obvious secrets from deploy logs before persistence or email.
     */
    public static function redact(string $log): string
    {
        // Terminal control noise comes off FIRST, and not only for readability:
        // a colour code can land inside a secret ("password=" ESC[0m "hunter2")
        // and split it so the patterns below never match. Stripping the escapes
        // first makes redaction see the real text. Sanitizing is idempotent, so
        // callers that already cleaned their output pay nothing.
        $log = DeployLogSanitizer::sanitize($log);

        $patterns = [
            '/(password|passwd|secret|token|api_key|apikey|authorization)\s*[=:]\s*\S+/iu' => '$1=[redacted]',
            '/(AWS_SECRET_ACCESS_KEY|AWS_ACCESS_KEY_ID|GITHUB_TOKEN|GITLAB_TOKEN)\s*=\s*\S+/u' => '$1=[redacted]',
            '/-----BEGIN [A-Z ]+PRIVATE KEY-----[\s\S]*?-----END [A-Z ]+PRIVATE KEY-----/u' => '[redacted private key]',
            // Strip tokens embedded in HTTPS clone URLs: https://x-access-token:TOKEN@github.com/…
            '#(https?://)[^/@\s]+:[^/@\s]+@#' => '$1[redacted]@',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $log = (string) preg_replace($pattern, $replacement, $log);
        }

        return $log;
    }
}

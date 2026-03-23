<?php

namespace App\Support;

class DeployLogRedactor
{
    /**
     * Strip obvious secrets from deploy logs before persistence or email.
     */
    public static function redact(string $log): string
    {
        $patterns = [
            '/(password|passwd|secret|token|api_key|apikey|authorization)\s*[=:]\s*\S+/iu' => '$1=[redacted]',
            '/(AWS_SECRET_ACCESS_KEY|AWS_ACCESS_KEY_ID|GITHUB_TOKEN|GITLAB_TOKEN)\s*=\s*\S+/u' => '$1=[redacted]',
            '/-----BEGIN [A-Z ]+PRIVATE KEY-----[\s\S]*?-----END [A-Z ]+PRIVATE KEY-----/u' => '[redacted private key]',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $log = (string) preg_replace($pattern, $replacement, $log);
        }

        return $log;
    }
}

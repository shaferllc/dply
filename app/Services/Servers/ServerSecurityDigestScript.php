<?php

declare(strict_types=1);

namespace App\Services\Servers;

/**
 * Builds and parses a lightweight SSH security digest scan.
 */
final class ServerSecurityDigestScript
{
    public function build(): string
    {
        return <<<'SH'
printf "DIGEST_BEGIN\n"
failed=$(grep -E "Failed password|Invalid user" /var/log/auth.log 2>/dev/null | wc -l | tr -d " ")
printf "auth_failed_lines=%s\n" "${failed:-0}"
if command -v fail2ban-client >/dev/null 2>&1; then
  active=$(systemctl is-active fail2ban 2>/dev/null || echo "unknown")
  printf "fail2ban_active=%s\n" "$active"
  printf "FAIL2BAN_BEGIN\n"
  fail2ban-client status 2>/dev/null | head -n 50
  printf "FAIL2BAN_END\n"
else
  printf "fail2ban_active=missing\n"
fi
printf "DIGEST_END\n"
SH;
    }

    /**
     * @return array<string, mixed>
     */
    public function parse(string $output, array $existingMeta = []): array
    {
        $authFailed = 0;
        $fail2banActive = null;
        $fail2banRaw = null;
        $jails = [];

        $inFail2ban = false;
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === 'FAIL2BAN_BEGIN') {
                $inFail2ban = true;
                $fail2banRaw = '';

                continue;
            }
            if ($line === 'FAIL2BAN_END') {
                $inFail2ban = false;

                continue;
            }
            if ($inFail2ban) {
                $fail2banRaw .= $line."\n";
                if (preg_match('/^Jail list:\s*(.+)$/', $line, $m)) {
                    $jails = array_filter(array_map('trim', explode(',', $m[1])));
                }

                continue;
            }
            if (str_starts_with($line, 'auth_failed_lines=')) {
                $authFailed = max(0, (int) substr($line, strlen('auth_failed_lines=')));
            }
            if (str_starts_with($line, 'fail2ban_active=')) {
                $fail2banActive = substr($line, strlen('fail2ban_active='));
            }
        }

        $meta = is_array($existingMeta) ? $existingMeta : [];
        $meta['security_digest_snapshot'] = [
            'checked_at' => now()->toIso8601String(),
            'auth_failed_lines' => $authFailed,
            'fail2ban_active' => $fail2banActive,
            'fail2ban_jails' => array_values($jails),
            'fail2ban_raw' => $fail2banRaw !== null ? trim($fail2banRaw) : null,
        ];

        return $meta;
    }
}

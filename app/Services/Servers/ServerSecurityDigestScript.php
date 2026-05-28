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
invalid=$(grep -c "Invalid user" /var/log/auth.log 2>/dev/null || echo 0)
password=$(grep -c "Failed password" /var/log/auth.log 2>/dev/null || echo 0)
printf "auth_invalid_user_lines=%s\n" "${invalid:-0}"
printf "auth_failed_password_lines=%s\n" "${password:-0}"
recent=$(tail -n 5000 /var/log/auth.log 2>/dev/null | grep -E "Failed password|Invalid user" | wc -l | tr -d " ")
printf "auth_failed_recent=%s\n" "${recent:-0}"
if command -v ufw >/dev/null 2>&1; then
  ufw_status=$(ufw status 2>/dev/null | head -n 1 | sed 's/^Status: //')
  printf "ufw_active=%s\n" "${ufw_status:-unknown}"
else
  printf "ufw_active=missing\n"
fi
if command -v sshd >/dev/null 2>&1; then
  pa=$(sshd -T 2>/dev/null | awk '/^passwordauthentication /{print $2; exit}')
  pr=$(sshd -T 2>/dev/null | awk '/^permitrootlogin /{print $2; exit}')
  printf "sshd_password_auth=%s\n" "${pa:-unknown}"
  printf "sshd_permit_root=%s\n" "${pr:-unknown}"
fi
if command -v fail2ban-client >/dev/null 2>&1; then
  active=$(systemctl is-active fail2ban 2>/dev/null || echo "unknown")
  printf "fail2ban_active=%s\n" "$active"
  printf "FAIL2BAN_BEGIN\n"
  fail2ban-client status 2>/dev/null | head -n 50
  printf "FAIL2BAN_END\n"
  jails=$(fail2ban-client status 2>/dev/null | sed -n 's/^Jail list:[[:space:]]*//p' | tr ',' ' ')
  for jail in $jails; do
    jail=$(echo "$jail" | xargs)
    [ -z "$jail" ] && continue
    printf "JAIL_BEGIN=%s\n" "$jail"
    fail2ban-client status "$jail" 2>/dev/null
    printf "JAIL_END\n"
  done
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
        $authInvalidUser = 0;
        $authFailedPassword = 0;
        $authFailedRecent = 0;
        $ufwActive = null;
        $sshdPasswordAuth = null;
        $sshdPermitRoot = null;
        $fail2banActive = null;
        $fail2banRaw = null;
        $jails = [];
        $jailRows = [];

        $inFail2ban = false;
        $inJail = false;
        $currentJail = null;
        $currentJailRaw = '';

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
            if (str_starts_with($line, 'JAIL_BEGIN=')) {
                $inJail = true;
                $currentJail = trim(substr($line, strlen('JAIL_BEGIN=')));
                $currentJailRaw = '';

                continue;
            }
            if ($line === 'JAIL_END') {
                if ($currentJail !== null && $currentJail !== '') {
                    $jailRows[] = $this->parseJailStatus($currentJail, $currentJailRaw);
                }
                $inJail = false;
                $currentJail = null;
                $currentJailRaw = '';

                continue;
            }
            if ($inJail) {
                $currentJailRaw .= $line."\n";

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
            if (str_starts_with($line, 'auth_invalid_user_lines=')) {
                $authInvalidUser = max(0, (int) substr($line, strlen('auth_invalid_user_lines=')));
            }
            if (str_starts_with($line, 'auth_failed_password_lines=')) {
                $authFailedPassword = max(0, (int) substr($line, strlen('auth_failed_password_lines=')));
            }
            if (str_starts_with($line, 'auth_failed_recent=')) {
                $authFailedRecent = max(0, (int) substr($line, strlen('auth_failed_recent=')));
            }
            if (str_starts_with($line, 'ufw_active=')) {
                $ufwActive = substr($line, strlen('ufw_active='));
            }
            if (str_starts_with($line, 'sshd_password_auth=')) {
                $sshdPasswordAuth = substr($line, strlen('sshd_password_auth='));
            }
            if (str_starts_with($line, 'sshd_permit_root=')) {
                $sshdPermitRoot = substr($line, strlen('sshd_permit_root='));
            }
            if (str_starts_with($line, 'fail2ban_active=')) {
                $fail2banActive = substr($line, strlen('fail2ban_active='));
            }
        }

        $meta = is_array($existingMeta) ? $existingMeta : [];
        $meta['security_digest_snapshot'] = [
            'checked_at' => now()->toIso8601String(),
            'auth_failed_lines' => $authFailed,
            'auth_invalid_user_lines' => $authInvalidUser,
            'auth_failed_password_lines' => $authFailedPassword,
            'auth_failed_recent' => $authFailedRecent,
            'ufw_active' => $ufwActive,
            'sshd_password_auth' => $sshdPasswordAuth,
            'sshd_permit_root' => $sshdPermitRoot,
            'fail2ban_active' => $fail2banActive,
            'fail2ban_jails' => array_values($jails),
            'fail2ban_jail_rows' => $jailRows,
            'fail2ban_raw' => $fail2banRaw !== null ? trim($fail2banRaw) : null,
        ];

        return $meta;
    }

    /**
     * @return array{
     *   name: string,
     *   currently_banned: ?int,
     *   total_banned: ?int,
     *   currently_failed: ?int,
     *   total_failed: ?int,
     *   banned_ips: list<string>,
     * }
     */
    public function parseJailStatus(string $name, string $raw): array
    {
        $currentlyBanned = $this->matchInt($raw, '/Currently banned:\s*(\d+)/');
        $totalBanned = $this->matchInt($raw, '/Total banned:\s*(\d+)/');
        $currentlyFailed = $this->matchInt($raw, '/Currently failed:\s*(\d+)/');
        $totalFailed = $this->matchInt($raw, '/Total failed:\s*(\d+)/');
        $bannedIps = [];

        if (preg_match('/Banned IP list:\s*(.+)$/m', $raw, $m)) {
            $bannedIps = array_values(array_filter(array_map('trim', preg_split('/\s+/', trim($m[1])) ?: [])));
        }

        return [
            'name' => $name,
            'currently_banned' => $currentlyBanned,
            'total_banned' => $totalBanned,
            'currently_failed' => $currentlyFailed,
            'total_failed' => $totalFailed,
            'banned_ips' => $bannedIps,
        ];
    }

    private function matchInt(string $raw, string $pattern): ?int
    {
        if (! preg_match($pattern, $raw, $m)) {
            return null;
        }

        return max(0, (int) $m[1]);
    }
}

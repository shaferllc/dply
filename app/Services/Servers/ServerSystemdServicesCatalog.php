<?php

namespace App\Services\Servers;

use App\Models\Server;

/**
 * Resolves allowlisted systemd unit names for inventory and remote actions (Services workspace).
 */
final class ServerSystemdServicesCatalog
{
    /**
     * Normalized unit names ending in .service, unique, sorted.
     *
     * @return list<string>
     */
    public function allowedUnitsForServer(Server $server): array
    {
        $defaults = $this->defaultUnitsResolved($server);
        $custom = $this->customUnitsFromMeta($server);
        $merged = array_merge($defaults, $custom);
        $merged = array_map(fn (string $u) => $this->normalizeUnit($u), $merged);
        $merged = array_unique($merged);
        sort($merged);

        return array_values($merged);
    }

    /**
     * Debian/Ubuntu package name for dpkg-query version display.
     */
    public function dpkgPackageForUnit(string $normalizedUnit, Server $server): string
    {
        $map = (array) config('server_services.systemd_unit_version_packages', []);

        foreach (config('server_services.systemd_units', []) as $entry) {
            if (! is_array($entry) || empty($entry['unit'])) {
                continue;
            }
            if ($entry['unit'] === 'php-fpm') {
                $php = $this->phpMinorVersion($server);
                $nu = 'php'.$php.'-fpm.service';
                if ($nu === $normalizedUnit) {
                    return 'php'.$php.'-fpm';
                }

                continue;
            }
            $nu = $this->normalizeUnit((string) $entry['unit']);
            if ($nu !== $normalizedUnit) {
                continue;
            }
            $b = $this->unitBasename($nu);
            $pkg = isset($entry['version_package']) && is_string($entry['version_package'])
                ? $entry['version_package']
                : $b;

            return $map[$b] ?? $pkg;
        }

        $base = $this->unitBasename($normalizedUnit);

        return $map[$base] ?? $base;
    }

    /**
     * Normalize and validate a unit name for read-only {@code systemctl status} (any running service).
     *
     * @throws \InvalidArgumentException
     */
    public function assertSafeUnitNameForStatus(string $unit): string
    {
        $normalized = $this->normalizeUnit($unit);
        if ($normalized === '' || ! str_ends_with($normalized, '.service')) {
            throw new \InvalidArgumentException(__('Invalid service unit.'));
        }
        $base = preg_replace('/\.service$/i', '', $normalized);
        if (! is_string($base) || $base === '' || ! preg_match('/^[a-zA-Z0-9@._-]+$/', $base)) {
            throw new \InvalidArgumentException(__('Invalid service unit.'));
        }

        return $normalized;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function assertAllowedOnServer(Server $server, string $unit): string
    {
        $normalized = $this->normalizeUnit($unit);
        $allowed = $this->allowedUnitsForServer($server);
        if (! in_array($normalized, $allowed, true)) {
            throw new \InvalidArgumentException(__('That service is not in the allowlist for this server.'));
        }

        return $normalized;
    }

    /**
     * Whether the Services UI should show an inline “Disable on boot” control for this unit
     * (see {@see config('server_services.systemd_units_inline_disable_at_boot')}).
     */
    public function shouldOfferInlineDisableAtBoot(string $normalizedUnit): bool
    {
        $base = strtolower((string) preg_replace('/\.service$/i', '', $normalizedUnit));
        if ($base === '') {
            return false;
        }

        $list = config('server_services.systemd_units_inline_disable_at_boot', []);
        if (! is_array($list)) {
            return false;
        }

        $allowed = [];
        foreach ($list as $item) {
            if (! is_string($item) || trim($item) === '') {
                continue;
            }
            $b = strtolower((string) preg_replace('/\.service$/i', '', trim($item)));
            if ($b !== '') {
                $allowed[$b] = true;
            }
        }

        return isset($allowed[$base]);
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function validateAndNormalizeCustomUnit(string $raw): string
    {
        $trim = trim($raw);
        if ($trim === '') {
            throw new \InvalidArgumentException(__('Enter a unit name.'));
        }
        if (strlen($trim) > 200) {
            throw new \InvalidArgumentException(__('That unit name is too long.'));
        }
        if (str_contains($trim, '..') || str_contains($trim, "\n") || str_contains($trim, ';')) {
            throw new \InvalidArgumentException(__('Invalid unit name.'));
        }
        if (! preg_match('/^[a-zA-Z0-9@._-]+$/', $trim)) {
            throw new \InvalidArgumentException(__('Use letters, numbers, and @ . _ - only.'));
        }

        return $this->normalizeUnit($trim);
    }

    /**
     * @return list<string>
     */
    protected function defaultUnitsResolved(Server $server): array
    {
        $out = [];
        foreach (config('server_services.systemd_units', []) as $entry) {
            if (! is_array($entry) || empty($entry['unit'])) {
                continue;
            }
            if ($entry['unit'] === 'php-fpm') {
                $php = $this->phpMinorVersion($server);
                $out[] = 'php'.$php.'-fpm.service';

                continue;
            }
            $out[] = $this->normalizeUnit((string) $entry['unit']);
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    protected function customUnitsFromMeta(Server $server): array
    {
        $meta = $server->meta ?? [];
        $raw = $meta['custom_systemd_services'] ?? [];
        if (! is_array($raw)) {
            return [];
        }
        $units = [];
        foreach ($raw as $item) {
            if (! is_string($item) || $item === '') {
                continue;
            }
            try {
                $units[] = $this->validateAndNormalizeCustomUnit($item);
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return $units;
    }

    public function normalizeUnit(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        if (! str_ends_with($name, '.service')) {
            $name .= '.service';
        }

        return $name;
    }

    /**
     * Lowercase unit basename without .service (for package map lookups).
     */
    protected function unitBasename(string $unit): string
    {
        $u = strtolower(trim($unit));
        if (str_ends_with($u, '.service')) {
            return substr($u, 0, -8);
        }

        return $u;
    }

    protected function phpMinorVersion(Server $server): string
    {
        $meta = $server->meta ?? [];
        $v = (string) ($meta['default_php_version'] ?? '8.3');
        if (! preg_match('/^\d+\.\d+$/', $v)) {
            $v = '8.3';
        }

        return $v;
    }

    /**
     * Bash {@code case} arms mapping unit basename → dpkg package (for inventory on the guest).
     */
    protected function bashDpkgPackageCaseArms(): string
    {
        $lines = [];
        foreach ((array) config('server_services.systemd_unit_version_packages', []) as $base => $pkg) {
            $b = preg_replace('/[^a-zA-Z0-9@._-]/', '', (string) $base);
            $p = preg_replace('/[^a-zA-Z0-9@+._-]/', '', (string) $pkg);
            if ($b === '' || $p === '') {
                continue;
            }
            $lines[] = $b.') pkg='.$p.' ;;';
        }

        return implode("\n    ", array_unique($lines));
    }

    /**
     * Unit basenames (no .service) that cannot be started/stopped/restarted from Services for this server.
     *
     * @return list<string>
     */
    public function statusOnlyUnitBasenamesForServer(Server $server): array
    {
        $org = $server->organization;

        return $org !== null ? $org->mergedServicesPreferences()['systemd_status_only_units'] : array_values(array_filter(array_map(
            static function (mixed $v): string {
                if (! is_string($v)) {
                    return '';
                }

                return strtolower(trim(str_replace('.service', '', $v)));
            },
            is_array(config('server_services.systemd_status_only_units', [])) ? config('server_services.systemd_status_only_units', []) : [],
        ), static fn (string $v) => $v !== ''));
    }

    /**
     * Whether this unit is policy-limited to status / notify only (no start/stop/reload/boot toggles).
     */
    public function isUnitStatusOnlyForServer(Server $server, string $normalizedUnit): bool
    {
        $base = strtolower((string) preg_replace('/\.service$/i', '', $normalizedUnit));
        if ($base === '') {
            return false;
        }
        $list = $this->statusOnlyUnitBasenamesForServer($server);

        return in_array($base, $list, true);
    }

    public function bootStateLikelyEnabled(?string $unitFileState): bool
    {
        $s = strtolower(trim((string) $unitFileState));

        return in_array($s, [
            'enabled',
            'static',
            'indirect',
            'alias',
            'linked',
            'generated',
            'enabled-runtime',
            'linked-runtime',
        ], true);
    }

    public function bootStateLikelyDisabled(?string $unitFileState): bool
    {
        $s = strtolower(trim((string) $unitFileState));

        return in_array($s, ['disabled', 'masked', 'bad'], true);
    }

    /**
     * Whether to offer “Enable at boot” (systemctl enable) for this systemctl is-enabled value.
     */
    public function bootMenuShowEnableAtBoot(?string $unitFileState): bool
    {
        $s = strtolower(trim((string) $unitFileState));

        if ($s === '' || $s === 'not-found') {
            return true;
        }

        if ($s === 'transient') {
            return false;
        }

        if ($this->bootStateLikelyDisabled($unitFileState)) {
            return true;
        }

        if ($this->bootStateLikelyEnabled($unitFileState)) {
            return false;
        }

        return true;
    }

    /**
     * Whether to offer “Disable at boot” (systemctl disable) for this systemctl is-enabled value.
     */
    public function bootMenuShowDisableAtBoot(?string $unitFileState): bool
    {
        $s = strtolower(trim((string) $unitFileState));

        if ($s === '' || $s === 'not-found') {
            return true;
        }

        if ($s === 'transient') {
            return false;
        }

        if ($this->bootStateLikelyEnabled($unitFileState)) {
            return true;
        }

        if ($this->bootStateLikelyDisabled($unitFileState)) {
            return false;
        }

        return true;
    }

    /**
     * Read-only inventory: all running {@code .service} units, capped by config. Output lines
     * {@code DPLY_SVC_ROW:unit|active|sub|timestamp|version|is-enabled|main-pid}.
     */
    public function buildInventoryScript(Server $server): string
    {
        unset($server);
        $max = max(1, min(2000, (int) config('server_services.systemd_inventory_max_units', 500)));
        $caseArms = $this->bashDpkgPackageCaseArms();
        $caseBlock = '';
        if ($caseArms !== '') {
            foreach (preg_split('/\R/', $caseArms) as $line) {
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }
                $caseBlock .= '    '.$line."\n";
            }
        }

        return str_replace(
            ['__MAX__', '__CASE_BLOCK__'],
            [(string) $max, $caseBlock],
            <<<'BASH'
export LANG=C
set +e
MAX=__MAX__
mapfile -t lines < <(systemctl list-units --type=service --state=running --no-legend --no-pager 2>/dev/null)
units=()
for line in "${lines[@]}"; do
  u=$(echo "$line" | awk '{print $1}')
  case "$u" in
    *.service) units+=("$u") ;;
  esac
done
i=0
for u in "${units[@]}"; do
  i=$((i+1))
  if [ "$i" -gt "$MAX" ]; then break; fi
  pkg="${u%.service}"
  case "$pkg" in
__CASE_BLOCK__    *) ;;
  esac
  ver=$(dpkg-query -W -f='${Version}' "$pkg" 2>/dev/null || true)
  act=$(systemctl show -p ActiveState --value "$u" 2>/dev/null | head -1)
  sub=$(systemctl show -p SubState --value "$u" 2>/dev/null | head -1)
  ts=$(systemctl show -p ActiveEnterTimestamp --value "$u" 2>/dev/null | head -1)
  en=$(systemctl is-enabled "$u" 2>/dev/null | head -1)
  pid=$(systemctl show -p MainPID --value "$u" 2>/dev/null | head -1)
  echo "DPLY_SVC_ROW:${u}|${act:-unknown}|${sub:-unknown}|${ts}|${ver}|${en:-}|${pid:-}"
done
if [ "$i" -eq 0 ]; then
  echo "DPLY_SVC_ROW:__none__|unknown|unknown||"
fi
exit 0
BASH
        );
    }
}

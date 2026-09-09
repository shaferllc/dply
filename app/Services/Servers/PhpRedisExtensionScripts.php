<?php

declare(strict_types=1);

namespace App\Services\Servers;

/**
 * phpredis must be enabled exactly once per SAPI. Apt's php{N}-redis plus
 * PECL (or a second phpenmod) leaves `extension=redis` in php.ini *and*
 * conf.d/20-redis.ini — PHP then warns "Module redis is already loaded"
 * and FPM can refuse to start.
 */
final class PhpRedisExtensionScripts
{
    /**
     * Strip leftover php.ini lines and collapse extra conf.d redis inis for
     * one catalog version (e.g. 8.4).
     */
    public static function dedupe(string $version): string
    {
        $version = trim($version);
        if ($version === '' || ! preg_match('/^\d+\.\d+/', $version)) {
            throw new \InvalidArgumentException('A PHP major.minor version is required.');
        }

        return self::dedupeBody(escapeshellarg($version), escapeshellarg('/etc/php/'.$version));
    }

    /**
     * Same cleanup, version taken from the box's default `php` binary.
     */
    public static function dedupeFromDetectedCli(): string
    {
        return implode("\n", [
            'PHPVER=$(php -r \'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;\' 2>/dev/null || true)',
            'if [ -n "$PHPVER" ]; then',
            self::indent(self::dedupeBody('"$PHPVER"', '"/etc/php/${PHPVER}"')),
            'fi',
        ]);
    }

    /**
     * List every `extension=redis` directive so a health-check dump shows
     * duplicates instead of only the "already loaded" warning.
     */
    public static function detectListing(): string
    {
        return <<<'BASH'
echo "── php redis load sites ──"
if php -v 2>&1 | grep -q 'already loaded'; then
  echo "PHP warned that a module is already loaded — listing redis directives:"
fi
found=0
for dir in /etc/php/*; do
  [ -d "$dir" ] || continue
  ver=$(basename "$dir")
  hits=$(grep -RHnE '^[[:space:]]*extension[[:space:]]*=[[:space:]]*"?redis' "$dir" --include='*.ini' 2>/dev/null || true)
  if [ -n "$hits" ]; then
    found=1
    count=$(printf '%s\n' "$hits" | grep -c .)
    echo "PHP ${ver}: ${count} redis directive(s)"
    printf '%s\n' "$hits"
    if [ "$count" -gt 1 ]; then
      echo "  → duplicate: keep mods-available/redis.ini (20-redis.ini) and remove the extras."
    fi
  fi
done
[ "$found" -eq 0 ] && echo "(no extension=redis directives found under /etc/php)"
BASH;
    }

    private static function dedupeBody(string $versionExpr, string $rootExpr): string
    {
        return <<<BASH
echo "[dply] checking for duplicate redis extension loads in PHP {$versionExpr}…"
for sapi in cli fpm apache2 cgi; do
  INI={$rootExpr}/\${sapi}/php.ini
  if [ -f "\$INI" ] && grep -qE '^[[:space:]]*extension[[:space:]]*=[[:space:]]*"?redis(\\.so)?"?' "\$INI"; then
    echo "[dply] removing extension=redis from \$INI"
    sed -i -E '/^[[:space:]]*extension[[:space:]]*=[[:space:]]*"?redis(\\.so)?"?[[:space:]]*\$/d' "\$INI"
  fi
  CONFD={$rootExpr}/\${sapi}/conf.d
  if [ -d "\$CONFD" ]; then
    keep=""
    for f in "\$CONFD"/20-redis.ini "\$CONFD"/*redis*.ini; do
      [ -e "\$f" ] || continue
      grep -qE '^[[:space:]]*extension[[:space:]]*=[[:space:]]*"?redis' "\$f" || continue
      if [ "\$(basename "\$f")" = "20-redis.ini" ]; then keep="\$f"; break; fi
      [ -z "\$keep" ] && keep="\$f"
    done
    for f in "\$CONFD"/*redis*.ini; do
      [ -e "\$f" ] || continue
      grep -qE '^[[:space:]]*extension[[:space:]]*=[[:space:]]*"?redis' "\$f" || continue
      if [ -n "\$keep" ] && [ "\$f" != "\$keep" ]; then
        echo "[dply] removing duplicate redis ini \$f"
        rm -f "\$f"
      fi
    done
  fi
done
if [ -f {$rootExpr}/mods-available/redis.ini ]; then
  phpenmod -v {$versionExpr} redis 2>/dev/null || true
fi
BASH;
    }

    private static function indent(string $script): string
    {
        return implode("\n", array_map(
            static fn (string $line): string => $line === '' ? '' : '  '.$line,
            explode("\n", $script),
        ));
    }
}

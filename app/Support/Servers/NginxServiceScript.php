<?php

declare(strict_types=1);

namespace App\Support\Servers;

/**
 * Shared bash for validating nginx config and applying it safely when the
 * daemon may not be running yet (first site on a fresh server).
 */
final class NginxServiceScript
{
    /**
     * Run `nginx -t`, then reload when active or start/enable when not.
     */
    public static function testAndReloadOrStartScript(): string
    {
        return <<<'BASH'
dply_nginx_test_and_reload_or_start() {
  nginx -t || return 1
  if systemctl is-active --quiet nginx 2>/dev/null; then
    systemctl reload nginx
    return $?
  fi
  if [ -f /run/nginx.pid ] && [ ! -s /run/nginx.pid ]; then
    rm -f /run/nginx.pid
  fi
  if [ -f /var/run/nginx.pid ] && [ ! -s /var/run/nginx.pid ]; then
    rm -f /var/run/nginx.pid
  fi
  if systemctl list-unit-files nginx.service 2>/dev/null | grep -qE '^nginx\.service'; then
    systemctl enable --now nginx
    return $?
  fi
  service nginx start 2>/dev/null || nginx
}
dply_nginx_test_and_reload_or_start
BASH;
    }
}

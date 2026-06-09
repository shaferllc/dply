<?php

declare(strict_types=1);

namespace App\Support\Servers;

/**
 * The default snapshot-bake script, shared by the per-provider bake commands
 * (DigitalOcean, Hetzner, …). It warms the apt cache and pre-installs the
 * packages every dply role needs (plus the ondrej/php repo and a baseline PHP
 * 8.3 stack), then wipes cloud-init state so the cloud can repersonalise each
 * new server built from the snapshot.
 *
 * It is provider-agnostic (plain Ubuntu) and idempotent with the per-server
 * provisioner: already-installed packages/steps are skip-fasted on first boot.
 * It deliberately does NOT run system upgrades — the base image is fresh and
 * ongoing security drift is handled by dply's recurring maintenance scheduler.
 */
final class SnapshotBakeScript
{
    public static function default(): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

echo "[dply-bake] disabling cloud-init unattended upgrades"
systemctl mask --now \
    apt-daily.service apt-daily.timer \
    apt-daily-upgrade.service apt-daily-upgrade.timer \
    unattended-upgrades.service 2>/dev/null || true

echo "[dply-bake] waiting for any in-flight apt"
for _ in $(seq 1 60); do
    if ! fuser /var/lib/apt/lists/lock /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock >/dev/null 2>&1; then
        break
    fi
    sleep 3
done

echo "[dply-bake] refreshing apt index (no upgrade — base image is fresh; recurring maintenance handles patches)"
apt-get update -qq

echo "[dply-bake] installing common packages"
apt-get install -y --no-install-recommends \
    curl wget gnupg ca-certificates lsb-release software-properties-common \
    git unzip zip jq rsync htop ncdu cron \
    python3-minimal python3-pip \
    nginx supervisor fail2ban redis-server

echo "[dply-bake] adding ondrej/php + php 8.3 baseline"
add-apt-repository -y ppa:ondrej/php
apt-get update -qq
apt-get install -y --no-install-recommends \
    php8.3 php8.3-cli php8.3-fpm php8.3-common php8.3-curl \
    php8.3-mbstring php8.3-xml php8.3-zip php8.3-mysql php8.3-redis \
    php8.3-bcmath php8.3-intl php8.3-gd

echo "[dply-bake] installing composer"
EXPECTED_SIG=$(curl -fsSL https://composer.github.io/installer.sig)
curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
ACTUAL_SIG=$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")
if [ "$EXPECTED_SIG" != "$ACTUAL_SIG" ]; then
    echo "[dply-bake] composer installer signature mismatch" >&2
    exit 1
fi
php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
rm -f /tmp/composer-setup.php

echo "[dply-bake] installing Node.js LTS"
curl -fsSL https://deb.nodesource.com/setup_lts.x | bash -
apt-get install -y --no-install-recommends nodejs

echo "[dply-bake] stopping services for clean boot from snapshot"
systemctl stop nginx php8.3-fpm supervisor fail2ban redis-server 2>/dev/null || true

echo "[dply-bake] trimming caches"
apt-get clean
rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /root/.bash_history

echo "[dply-bake] resetting cloud-init + machine-id so the cloud repersonalises new servers"
cloud-init clean --logs --machine-id 2>/dev/null || true
truncate -s 0 /etc/machine-id 2>/dev/null || true
rm -f /var/lib/dbus/machine-id 2>/dev/null || true
rm -rf /var/lib/cloud/instances/* /var/log/cloud-init*.log 2>/dev/null || true

echo "[dply-bake] done"
BASH;
    }
}

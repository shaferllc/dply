<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DigitalOceanService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use phpseclib3\Net\SSH2;

/**
 * Bake a custom DigitalOcean snapshot to use as the default base image when
 * provisioning new droplets. Vanilla Ubuntu boots through ~30–90s of cloud-init
 * apt-daily holds plus 2–4 minutes of package downloads on first provision; a
 * pre-baked image collapses that into a one-shot boot.
 *
 * Flow: create a throwaway droplet → SSH in → run a bake script → power off →
 * snapshot → wait for the snapshot action to complete → destroy the droplet →
 * print the new image ID. Set DIGITALOCEAN_DEFAULT_IMAGE to that ID.
 */
class SnapshotBakeDigitalOceanCommand extends Command
{
    protected $signature = 'dply:do:snapshot:bake
        {--token= : DO API token (else env DPLY_SNAPSHOT_DO_TOKEN or DIGITALOCEAN_TOKEN)}
        {--region=nyc1 : DO region slug for the bake droplet}
        {--size=s-1vcpu-1gb : DO size slug for the bake droplet}
        {--base-image= : Base image slug (default: services.digitalocean.default_image)}
        {--name= : Snapshot name (default: dply-base-<date>-<rand>)}
        {--script= : Path to a bake script; if omitted, uses the embedded default}
        {--ssh-key-id=* : Existing DO SSH key id(s) to attach instead of generating one}
        {--ssh-ready-timeout=300 : Seconds to wait for SSH-ready on the bake droplet}
        {--bake-timeout=2400 : Seconds the bake script may run}
        {--snapshot-timeout=2400 : Seconds to wait for the snapshot action to complete}
        {--keep-droplet : Do not destroy the bake droplet after snapshotting}
        {--no-snapshot : Run the bake script and stop (debug only; implies --keep-droplet)}
        {--json : Emit a JSON envelope on stdout}';

    protected $description = 'Bake a reusable DigitalOcean droplet snapshot to speed up future server provisioning.';

    public function handle(): int
    {
        $token = $this->resolveToken();
        if ($token === null) {
            $this->error('Set --token=... or env DPLY_SNAPSHOT_DO_TOKEN / DIGITALOCEAN_TOKEN.');

            return self::FAILURE;
        }

        try {
            $do = new DigitalOceanService($token);
            $do->validateToken();
        } catch (\Throwable $e) {
            $this->error('DigitalOcean token check failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $bakeScript = $this->loadBakeScript();
        if ($bakeScript === null) {
            return self::FAILURE;
        }

        $baseImage = (string) ($this->option('base-image')
            ?: config('services.digitalocean.default_image', 'ubuntu-24-04-x64'));
        $snapshotName = (string) ($this->option('name') ?: $this->defaultSnapshotName());
        $dropletName = 'dply-snap-bake-'.Str::lower(Str::random(8));

        [$keyIds, $privateKey, $tempKeyId] = $this->resolveSshKey($do);
        if ($keyIds === []) {
            $this->error('No SSH key available for the bake droplet — pass --ssh-key-id or allow temp key generation.');

            return self::FAILURE;
        }

        $this->info("Creating bake droplet «{$dropletName}» from {$baseImage} in {$this->option('region')} ({$this->option('size')})…");

        $droplet = $do->createDroplet(
            name: $dropletName,
            region: (string) $this->option('region'),
            size: (string) $this->option('size'),
            image: $baseImage,
            sshKeyIds: $keyIds,
            options: ['tags' => ['dply', 'dply-snapshot-bake']],
        );

        $dropletId = (int) ($droplet['id'] ?? 0);
        if ($dropletId <= 0) {
            $this->error('DigitalOcean did not return a droplet id.');

            return self::FAILURE;
        }

        $this->info("Bake droplet id={$dropletId}. Waiting for IPv4…");

        try {
            $ip = $this->waitForDropletIp($do, $dropletId);
            $this->info("Droplet IP: {$ip}");

            if ($privateKey === null) {
                $this->warn('Using a pre-existing DO SSH key — assuming the matching private key is in your local agent (skipping in-process SSH).');
            } else {
                $this->waitForSshReady($ip, $privateKey, (int) $this->option('ssh-ready-timeout'));
                $this->runBakeScript($ip, $privateKey, $bakeScript, (int) $this->option('bake-timeout'));
            }

            if ($this->option('no-snapshot')) {
                $this->warn('--no-snapshot set; leaving the droplet running so you can inspect it.');
                if ($this->option('json')) {
                    $this->emitJson([
                        'droplet_id' => $dropletId,
                        'droplet_ip' => $ip,
                        'snapshot_id' => null,
                        'snapshot_name' => null,
                    ]);
                }

                return self::SUCCESS;
            }

            $this->info('Powering off droplet for crash-consistent snapshot…');
            $powerOffAction = $do->powerOffDroplet($dropletId);
            $do->waitForDropletAction(
                $dropletId,
                (int) $powerOffAction['id'],
                timeoutSeconds: 600,
                pollSeconds: 5,
                onTick: function (array $a): void {
                    $this->line('  [power_off] status='.($a['status'] ?? '?'));
                },
            );

            $this->info("Snapshotting as «{$snapshotName}»…");
            $snapAction = $do->snapshotDroplet($dropletId, $snapshotName);
            $do->waitForDropletAction(
                $dropletId,
                (int) $snapAction['id'],
                timeoutSeconds: (int) $this->option('snapshot-timeout'),
                pollSeconds: 15,
                onTick: function (array $a): void {
                    $this->line('  [snapshot] status='.($a['status'] ?? '?').' started='.($a['started_at'] ?? '—'));
                },
            );

            $snapshotId = $this->locateSnapshotId($do, $snapshotName, $dropletId);
            if ($snapshotId === null) {
                $this->warn('Snapshot action completed but the snapshot is not yet listed; DO sometimes needs a few seconds to surface it. Check `dply:do:snapshot:list` shortly.');
            } else {
                $this->info("Snapshot ready: id={$snapshotId} name={$snapshotName}");
                $this->line("Set <fg=cyan>DIGITALOCEAN_DEFAULT_IMAGE={$snapshotId}</> to use it for future droplets.");
            }

            if ($this->option('json')) {
                $this->emitJson([
                    'droplet_id' => $dropletId,
                    'droplet_ip' => $ip,
                    'snapshot_id' => $snapshotId,
                    'snapshot_name' => $snapshotName,
                ]);
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Bake failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            if (! $this->option('keep-droplet') && ! $this->option('no-snapshot')) {
                try {
                    $do->destroyDroplet($dropletId);
                    $this->line("Destroyed bake droplet {$dropletId}.");
                } catch (\Throwable $e) {
                    $this->warn('Could not destroy bake droplet '.$dropletId.': '.$e->getMessage());
                }
            }

            if ($tempKeyId !== null) {
                try {
                    $do->deleteSshKey($tempKeyId);
                    $this->line("Removed temp DO SSH key {$tempKeyId}.");
                } catch (\Throwable $e) {
                    $this->warn('Could not delete temp DO SSH key '.$tempKeyId.': '.$e->getMessage());
                }
            }
        }
    }

    private function resolveToken(): ?string
    {
        foreach ([$this->option('token'), env('DPLY_SNAPSHOT_DO_TOKEN'), env('DIGITALOCEAN_TOKEN')] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function loadBakeScript(): ?string
    {
        $path = $this->option('script');
        if (is_string($path) && $path !== '') {
            if (! is_file($path) || ! is_readable($path)) {
                $this->error("Bake script not readable: {$path}");

                return null;
            }

            return (string) file_get_contents($path);
        }

        return $this->defaultBakeScript();
    }

    private function defaultSnapshotName(): string
    {
        return 'dply-base-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(4));
    }

    /**
     * @return array{0: list<int|string>, 1: string|null, 2: int|null}  [keyIds, privateKey, tempKeyId]
     */
    private function resolveSshKey(DigitalOceanService $do): array
    {
        $existing = (array) $this->option('ssh-key-id');
        $existing = array_values(array_filter($existing, static fn ($v) => $v !== null && $v !== ''));

        if ($existing !== []) {
            $ids = array_map(static fn ($v) => is_numeric($v) ? (int) $v : (string) $v, $existing);

            return [$ids, null, null];
        }

        $rsa = RSA::createKey(2048);
        $privateKey = $rsa->toString('OpenSSH');
        $publicKey = $rsa->getPublicKey()->toString('OpenSSH');
        $name = 'dply-snapshot-bake-'.Str::lower(Str::random(8));

        $key = $do->addSshKey($name, $publicKey);
        $keyId = $key['id'] ?? null;
        if (! is_int($keyId) && ! is_string($keyId)) {
            throw new \RuntimeException('DO did not return an SSH key id.');
        }

        return [[$keyId], $privateKey, is_int($keyId) ? $keyId : (int) $keyId];
    }

    private function waitForDropletIp(DigitalOceanService $do, int $dropletId, int $timeoutSeconds = 300): string
    {
        $deadline = time() + $timeoutSeconds;
        while (time() < $deadline) {
            $droplet = $do->getDroplet($dropletId);
            $ip = DigitalOceanService::getDropletPublicIp($droplet);
            if (is_string($ip) && $ip !== '') {
                return $ip;
            }
            sleep(5);
        }

        throw new \RuntimeException("Droplet {$dropletId} never received a public IP.");
    }

    private function waitForSshReady(string $ip, string $privateKey, int $timeoutSeconds): void
    {
        $deadline = time() + max(30, $timeoutSeconds);
        $key = PublicKeyLoader::load($privateKey);
        $attempt = 0;

        while (time() < $deadline) {
            $attempt++;
            try {
                $ssh = new SSH2($ip, 22, 8);
                if ($ssh->login('root', $key)) {
                    $ssh->disconnect();
                    $this->info("SSH ready after {$attempt} attempt(s).");

                    return;
                }
            } catch (\Throwable) {
                // fall through to retry
            }
            if ($attempt % 5 === 0) {
                $this->line("  waiting for SSH on {$ip} (attempt {$attempt})…");
            }
            sleep(5);
        }

        throw new \RuntimeException("SSH never became ready on {$ip}.");
    }

    private function runBakeScript(string $ip, string $privateKey, string $script, int $timeoutSeconds): void
    {
        $this->info('Uploading and running bake script…');

        $key = PublicKeyLoader::load($privateKey);
        $ssh = new SSH2($ip, 22, 15);
        if (! $ssh->login('root', $key)) {
            throw new \RuntimeException('SSH login failed during bake.');
        }

        $remotePath = '/root/dply-bake-'.Str::lower(Str::random(8)).'.sh';
        $heredoc = "cat > {$remotePath} <<'DPLY_BAKE_EOF'\n".$script."\nDPLY_BAKE_EOF\nchmod +x {$remotePath}";
        $ssh->setTimeout(60);
        $ssh->exec($heredoc);

        $ssh->setTimeout(max(60, $timeoutSeconds));
        $ssh->exec("bash {$remotePath} 2>&1", function (string $chunk): void {
            $this->getOutput()->write($chunk);
        });

        $exit = $ssh->getExitStatus();
        $ssh->disconnect();

        if ($exit !== 0) {
            throw new \RuntimeException("Bake script exited with status {$exit}.");
        }
    }

    private function locateSnapshotId(DigitalOceanService $do, string $name, int $dropletId): ?string
    {
        $snapshots = $do->getSnapshots('droplet');
        foreach ($snapshots as $snap) {
            if (($snap['name'] ?? null) !== $name) {
                continue;
            }
            $resourceId = $snap['resource_id'] ?? null;
            if ($resourceId !== null && (int) $resourceId !== $dropletId) {
                continue;
            }
            $id = $snap['id'] ?? null;

            return is_scalar($id) ? (string) $id : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emitJson(array $payload): void
    {
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Default bake script — warms the apt cache and pre-installs the packages
     * every dply role needs, plus the ondrej/php repo and a baseline PHP 8.3
     * stack. Does NOT run system upgrades: the DO base image is already fresh,
     * and ongoing security drift is handled by dply's recurring maintenance
     * scheduler (config/server_provision.php :: preempt_cloud_init_upgrades).
     * Idempotent with the per-server provisioner: already-installed packages
     * are skipped on first-boot provision.
     *
     * Cloud-init state is wiped at the end so DO can re-personalise hostname,
     * SSH host keys, and machine-id on each new droplet built from this image.
     */
    private function defaultBakeScript(): string
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

echo "[dply-bake] resetting cloud-init + machine-id so DO repersonalises new droplets"
cloud-init clean --logs --machine-id 2>/dev/null || true
truncate -s 0 /etc/machine-id 2>/dev/null || true
rm -f /var/lib/dbus/machine-id 2>/dev/null || true
rm -rf /var/lib/cloud/instances/* /var/log/cloud-init*.log 2>/dev/null || true

echo "[dply-bake] done"
BASH;
    }
}

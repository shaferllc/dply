<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\HetznerService;
use App\Support\Servers\SnapshotBakeScript;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use phpseclib3\Net\SSH2;

/**
 * Bake a reusable Hetzner Cloud snapshot to use as the base image when
 * provisioning new servers. Vanilla Ubuntu boots through cloud-init apt holds
 * plus minutes of package downloads on first provision; a pre-baked image
 * collapses that into a one-shot boot + a skip-fast setup script.
 *
 * Flow: create a throwaway server → SSH in → run the shared bake script →
 * power off → create_image (snapshot) → wait for the action → delete the
 * server → print the new image id.
 *
 * Unlike DigitalOcean, Hetzner Cloud snapshots are GLOBAL across locations, so
 * a single baked id works for every region — set it as HETZNER_BAKED_SNAPSHOT.
 */
class SnapshotBakeHetznerCommand extends Command
{
    protected $signature = 'dply:hetzner:snapshot:bake
        {--token= : Hetzner API token (else env DPLY_SNAPSHOT_HETZNER_TOKEN / DPLY_MANAGED_HETZNER_API_TOKEN / HETZNER_API_TOKEN / HETZNER_TOKEN)}
        {--location=fsn1 : Hetzner location for the bake server}
        {--type=cx22 : Hetzner server type for the bake server}
        {--base-image= : Base image name (default: services.hetzner.default_image)}
        {--name= : Snapshot description (default: dply-base-<date>-<rand>)}
        {--script= : Path to a bake script; if omitted, uses the shared default}
        {--ssh-key-id=* : Existing Hetzner SSH key id(s) to attach instead of generating one}
        {--firewall-id= : Optional Cloud Firewall id to attach (if your project label-selects firewalls that would block SSH)}
        {--ssh-ready-timeout=300 : Seconds to wait for SSH-ready on the bake server}
        {--bake-timeout=2400 : Seconds the bake script may run}
        {--snapshot-timeout=2400 : Seconds to wait for the create_image action}
        {--keep-server : Do not delete the bake server after snapshotting}
        {--no-snapshot : Run the bake script and stop (debug only; implies --keep-server)}
        {--json : Emit a JSON envelope on stdout}';

    protected $description = 'Bake a reusable Hetzner Cloud snapshot to speed up future server provisioning.';

    public function handle(): int
    {
        $token = $this->resolveToken();
        if ($token === null) {
            $this->error('Set --token=... or env DPLY_SNAPSHOT_HETZNER_TOKEN / DPLY_MANAGED_HETZNER_API_TOKEN / HETZNER_API_TOKEN / HETZNER_TOKEN.');

            return self::FAILURE;
        }

        try {
            $hz = HetznerService::fromToken($token);
            $hz->validateToken();
        } catch (\Throwable $e) {
            $this->error('Hetzner token check failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $bakeScript = $this->loadBakeScript();
        if ($bakeScript === null) {
            return self::FAILURE;
        }

        $baseImage = (string) ($this->option('base-image')
            ?: config('services.hetzner.default_image', 'ubuntu-24.04'));
        $snapshotName = (string) ($this->option('name') ?: $this->defaultSnapshotName());
        $serverName = 'dply-snap-bake-'.Str::lower(Str::random(8));
        $location = (string) $this->option('location');
        $type = (string) $this->option('type');

        [$keyIds, $privateKey, $tempKeyId] = $this->resolveSshKey($hz);
        if ($keyIds === []) {
            $this->error('No SSH key available for the bake server — pass --ssh-key-id or allow temp key generation.');

            return self::FAILURE;
        }

        $firewallIds = [];
        $firewallOpt = $this->option('firewall-id');
        if (is_string($firewallOpt) && trim($firewallOpt) !== '') {
            $firewallIds = [(int) $firewallOpt];
        }

        $this->info("Creating bake server «{$serverName}» from {$baseImage} in {$location} ({$type})…");

        $serverId = $hz->createInstance(
            name: $serverName,
            location: $location,
            serverType: $type,
            image: $baseImage,
            sshKeyIds: $keyIds,
            firewallIds: $firewallIds,
        );

        if ($serverId <= 0) {
            $this->error('Hetzner did not return a server id.');

            return self::FAILURE;
        }

        $this->info("Bake server id={$serverId}. Waiting for IPv4…");

        try {
            $ip = $this->waitForServerIp($hz, $serverId);
            $this->info("Server IP: {$ip}");

            if ($privateKey === null) {
                $this->warn('Using a pre-existing Hetzner SSH key — assuming the matching private key is in your local agent (skipping in-process SSH).');
            } else {
                $this->waitForSshReady($ip, $privateKey, (int) $this->option('ssh-ready-timeout'));
                $this->runBakeScript($ip, $privateKey, $bakeScript, (int) $this->option('bake-timeout'));
            }

            if ($this->option('no-snapshot')) {
                $this->warn('--no-snapshot set; leaving the server running so you can inspect it.');
                if ($this->option('json')) {
                    $this->emitJson(['server_id' => $serverId, 'server_ip' => $ip, 'snapshot_id' => null, 'snapshot_name' => null]);
                }

                return self::SUCCESS;
            }

            $this->info('Powering off server for a crash-consistent snapshot…');
            $powerOff = $hz->powerOffServer($serverId);
            $hz->waitForAction(
                (int) $powerOff['id'],
                timeoutSeconds: 600,
                pollSeconds: 5,
                onTick: fn (array $a) => $this->line('  [power_off] status='.($a['status'] ?? '?')),
            );

            $this->info("Creating snapshot «{$snapshotName}»…");
            $image = $hz->createImageFromServer($serverId, $snapshotName, ['dply' => 'snapshot-bake']);
            $hz->waitForAction(
                (int) $image['action']['id'],
                timeoutSeconds: (int) $this->option('snapshot-timeout'),
                pollSeconds: 15,
                onTick: fn (array $a) => $this->line('  [snapshot] status='.($a['status'] ?? '?').' progress='.($a['progress'] ?? '—')),
            );

            $snapshotId = (string) $image['image_id'];
            $this->info("Snapshot ready: id={$snapshotId} name={$snapshotName}");
            $this->line('Hetzner snapshots are global across locations. Wire it up with a single id:');
            $this->line("  <fg=cyan>HETZNER_BAKED_SNAPSHOT={$snapshotId}</>");
            $this->line('New non-managed Hetzner servers then launch from the snapshot and skip-fast the setup script.');

            if ($this->option('json')) {
                $this->emitJson(['server_id' => $serverId, 'server_ip' => $ip, 'snapshot_id' => $snapshotId, 'snapshot_name' => $snapshotName]);
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Bake failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            if (! $this->option('keep-server') && ! $this->option('no-snapshot')) {
                try {
                    $hz->destroyInstance($serverId);
                    $this->line("Deleted bake server {$serverId}.");
                } catch (\Throwable $e) {
                    $this->warn('Could not delete bake server '.$serverId.': '.$e->getMessage());
                }
            }

            if ($tempKeyId !== null) {
                try {
                    $hz->deleteSshKey($tempKeyId);
                    $this->line("Removed temp Hetzner SSH key {$tempKeyId}.");
                } catch (\Throwable $e) {
                    $this->warn('Could not delete temp Hetzner SSH key '.$tempKeyId.': '.$e->getMessage());
                }
            }
        }
    }

    private function resolveToken(): ?string
    {
        foreach ([
            $this->option('token'),
            ...config('dply.snapshot_hetzner_tokens', []),
        ] as $candidate) {
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

        return SnapshotBakeScript::default();
    }

    private function defaultSnapshotName(): string
    {
        return 'dply-base-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(4));
    }

    /**
     * @return array{0: list<int|string>, 1: string|null, 2: int|null} [keyIds, privateKey, tempKeyId]
     */
    private function resolveSshKey(HetznerService $hz): array
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

        $key = $hz->addSshKey($name, $publicKey);
        $keyId = $key['id'] ?? null;
        if (! is_int($keyId) && ! is_string($keyId)) {
            throw new \RuntimeException('Hetzner did not return an SSH key id.');
        }

        return [[$keyId], $privateKey, (int) $keyId];
    }

    private function waitForServerIp(HetznerService $hz, int $serverId, int $timeoutSeconds = 300): string
    {
        $deadline = time() + $timeoutSeconds;
        while (time() < $deadline) {
            $server = $hz->getInstance($serverId);
            $ip = HetznerService::getPublicIp($server);
            if (is_string($ip) && $ip !== '') {
                return $ip;
            }
            sleep(5);
        }

        throw new \RuntimeException("Server {$serverId} never received a public IP.");
    }

    private function waitForSshReady(string $ip, string $privateKey, int $timeoutSeconds): void
    {
        $deadline = time() + max(30, $timeoutSeconds);
        $key = PublicKeyLoader::load($privateKey);
        if (! $key instanceof PrivateKey) {
            throw new \RuntimeException('SSH private key could not be loaded as a private key.');
        }
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
                // retry
            }
            if ($attempt % 5 === 0) {
                $this->line("  waiting for SSH on {$ip} (attempt {$attempt})…");
            }
            sleep(5);
        }

        throw new \RuntimeException("SSH never became ready on {$ip} (if your Hetzner project label-selects a firewall, attach it with --firewall-id).");
    }

    private function runBakeScript(string $ip, string $privateKey, string $script, int $timeoutSeconds): void
    {
        $this->info('Uploading and running bake script…');

        $key = PublicKeyLoader::load($privateKey);
        if (! $key instanceof PrivateKey) {
            throw new \RuntimeException('SSH private key could not be loaded as a private key.');
        }
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emitJson(array $payload): void
    {
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

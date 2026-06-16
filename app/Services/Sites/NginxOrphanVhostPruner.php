<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Server;
use App\Models\Site;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;
use App\Services\SshConnectionFactory;
use App\Support\Servers\NginxServiceScript;
use Illuminate\Support\Str;

/**
 * Finds and removes orphaned dply-managed nginx vhost files — `dply-*.conf`
 * files in sites-available/sites-enabled whose owning {@see Site} no longer
 * exists in the database.
 *
 * This is the self-heal for the failure mode that took tracely.cloud down: a
 * site recreate (or domain change that re-froze the basename) leaves the old
 * vhost on disk, and because dply never cleaned it up, two enabled vhosts ended
 * up declaring the same `server_name`. nginx keeps the one that loads first
 * (sites-enabled is globbed in sorted order) and logs the rest as "conflicting
 * server name ... ignored" — a warning, not an error — so the dead block can
 * silently shadow the live one for hours.
 *
 * Safety: a file is only ever removed when it (1) matches `dply-*.conf`,
 * (2) carries dply's {@see NginxConfigGuard::OWNERSHIP_MARKER} ownership stamp
 * (so a hand-authored vhost is never touched), and (3) has an extractable site
 * id token in its basename for which NO live site exists. Any file we can't
 * positively classify as an orphan is kept.
 */
final class NginxOrphanVhostPruner
{
    /**
     * Crockford-base32 ULID token (the site id baked into a vhost basename).
     * Matched case-insensitively; dply ids are lowercase.
     */
    private const ID_TOKEN = '/[0-9a-hjkmnp-tv-z]{26}/i';

    public function __construct(private readonly SshConnectionFactory $connections = new SshConnectionFactory) {}

    /**
     * Scan a server's nginx vhosts and classify each dply-managed file as kept
     * or orphaned. Read-only — opens its own connection.
     *
     * @return array{orphans: list<array{basename: string, site_id: ?string, server_names: string, in_available: bool, in_enabled: bool}>, kept: int}
     */
    public function scan(Server $server): array
    {
        return $this->classify($server, $this->connections->forServer($server));
    }

    /**
     * Remove every orphaned dply vhost on the server, then nginx -t + reload.
     *
     * @return array{removed: list<string>, kept: int, reloaded: bool, output: string}
     */
    public function prune(Server $server, ?ConsoleEmitter $emit = null): array
    {
        $ssh = $this->connections->forServer($server);
        $scan = $this->classify($server, $ssh);

        $basenames = array_map(static fn (array $o): string => $o['basename'], $scan['orphans']);

        return $this->removeBasenames($server, $ssh, $basenames, $scan['kept'], $emit);
    }

    /**
     * Auto-heal path used during an apply: given basenames nginx reported as
     * shadowing this site, remove only the ones that are confirmed orphans
     * (reusing the already-open connection), then reload. Returns the basenames
     * actually removed so the caller can decide whether the conflict cleared.
     *
     * @param  list<string>  $candidateBasenames
     * @return array{removed: list<string>, reloaded: bool, output: string}
     */
    public function pruneShadowing(Server $server, SshConnection $ssh, array $candidateBasenames, ?ConsoleEmitter $emit = null): array
    {
        $candidates = array_values(array_unique(array_filter(array_map(
            static fn (string $b): string => trim(Str::of($b)->basename('.conf')->toString()),
            $candidateBasenames,
        ))));
        if ($candidates === []) {
            return ['removed' => [], 'reloaded' => false, 'output' => ''];
        }

        $scan = $this->classify($server, $ssh);
        $orphanSet = array_flip(array_map(static fn (array $o): string => $o['basename'], $scan['orphans']));

        $toRemove = array_values(array_filter($candidates, static fn (string $b): bool => isset($orphanSet[$b])));

        $result = $this->removeBasenames($server, $ssh, $toRemove, $scan['kept'], $emit);

        return ['removed' => $result['removed'], 'reloaded' => $result['reloaded'], 'output' => $result['output']];
    }

    /**
     * Read every dply-* vhost off the box and split into orphans vs kept.
     *
     * @return array{orphans: list<array{basename: string, site_id: ?string, server_names: string, in_available: bool, in_enabled: bool}>, kept: int}
     */
    private function classify(Server $server, SshConnection $ssh): array
    {
        $available = rtrim((string) config('sites.nginx_sites_available'), '/');
        $enabled = rtrim((string) config('sites.nginx_sites_enabled'), '/');
        $marker = NginxConfigGuard::OWNERSHIP_MARKER;

        $script = <<<BASH
shopt -s nullglob
declare -A seen
for p in {$available}/dply-*.conf {$enabled}/dply-*.conf; do
  bn=\$(basename "\$p")
  [ -n "\${seen[\$bn]}" ] && continue
  seen[\$bn]=1
  avail="{$available}/\$bn"
  link="{$enabled}/\$bn"
  src=""
  [ -f "\$avail" ] && src="\$avail"
  [ -z "\$src" ] && [ -e "\$link" ] && src="\$link"
  has_avail=0; [ -f "\$avail" ] && has_avail=1
  has_link=0; [ -e "\$link" ] && has_link=1
  marker=0; grep -q '{$marker}' "\$src" 2>/dev/null && marker=1
  names=\$(grep -hoiE 'server_name[^;]*' "\$src" 2>/dev/null | head -1 | sed -E 's/server_name//I; s/^[[:space:]]+//; s/[[:space:]]+/ /g')
  printf 'FILE\t%s\t%s\t%s\t%s\t%s\n' "\$bn" "\$has_avail" "\$has_link" "\$marker" "\$names"
done
BASH;

        $out = $ssh->exec($script, 60);

        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $out) ?: [] as $line) {
            $parts = explode("\t", $line);
            if (count($parts) < 5 || $parts[0] !== 'FILE') {
                continue;
            }
            $rows[] = [
                'basename' => Str::of($parts[1])->basename('.conf')->toString(),
                'in_available' => $parts[2] === '1',
                'in_enabled' => $parts[3] === '1',
                'managed' => $parts[4] === '1',
                'server_names' => trim($parts[5] ?? ''),
            ];
        }

        // Resolve which id tokens correspond to live sites in one query.
        $allTokens = [];
        foreach ($rows as $row) {
            foreach ($this->idTokens($row['basename']) as $token) {
                $allTokens[$token] = true;
            }
        }
        $liveIds = $allTokens === []
            ? []
            : Site::query()->whereIn('id', array_keys($allTokens))->pluck('id')->map('strval')->all();
        $liveIds = array_flip($liveIds);

        $orphans = [];
        $kept = 0;
        foreach ($rows as $row) {
            $tokens = $this->idTokens($row['basename']);
            $hasLiveOwner = false;
            foreach ($tokens as $token) {
                if (isset($liveIds[$token])) {
                    $hasLiveOwner = true;
                    break;
                }
            }

            // Orphan only when dply wrote it, we found an id token, and no live
            // site claims any of its tokens. Unclassifiable files are kept.
            if ($row['managed'] && $tokens !== [] && ! $hasLiveOwner) {
                $orphans[] = [
                    'basename' => $row['basename'],
                    'site_id' => $tokens[0] ?? null,
                    'server_names' => $row['server_names'],
                    'in_available' => $row['in_available'],
                    'in_enabled' => $row['in_enabled'],
                ];

                continue;
            }

            $kept++;
        }

        return ['orphans' => $orphans, 'kept' => $kept];
    }

    /**
     * @param  list<string>  $basenames
     * @return array{removed: list<string>, kept: int, reloaded: bool, output: string}
     */
    private function removeBasenames(Server $server, SshConnection $ssh, array $basenames, int $kept, ?ConsoleEmitter $emit): array
    {
        $basenames = array_values(array_unique(array_filter($basenames, static fn (string $b): bool => $b !== '')));

        if ($basenames === []) {
            $emit?->step('nginx', 'no orphan vhosts found — nothing to prune');

            return ['removed' => [], 'kept' => $kept, 'reloaded' => false, 'output' => ''];
        }

        $available = rtrim((string) config('sites.nginx_sites_available'), '/');
        $enabled = rtrim((string) config('sites.nginx_sites_enabled'), '/');

        $rm = [];
        foreach ($basenames as $bn) {
            $rm[] = 'rm -f '.escapeshellarg($enabled.'/'.$bn.'.conf').' '.escapeshellarg($available.'/'.$bn.'.conf');
            $emit?->step('nginx', 'removing orphan vhost: '.$bn.'.conf');
        }

        $command = implode(' && ', $rm).' && '.NginxServiceScript::testAndReloadOrStartScript();

        $out = $ssh->exec(sprintf(
            '(%s) 2>&1; printf "\nDPLY_NGINX_PRUNE_EXIT:%%s" "$?"',
            $this->privilegedCommand($server, $command),
        ), 120);

        if (! preg_match('/DPLY_NGINX_PRUNE_EXIT:0\s*$/', $out)) {
            $emit?->error('orphan prune reload failed: '.Str::limit($out, 1000), 'nginx');

            throw new \RuntimeException('Nginx orphan prune failed. Output: '.Str::limit($out, 2000));
        }

        $emit?->success(sprintf('pruned %d orphan vhost%s — reload OK', count($basenames), count($basenames) === 1 ? '' : 's'), 'nginx');

        return ['removed' => $basenames, 'kept' => $kept, 'reloaded' => true, 'output' => $out];
    }

    /**
     * Site id tokens (ULIDs) embedded in a vhost basename.
     *
     * @return list<string>
     */
    private function idTokens(string $basename): array
    {
        if (! preg_match_all(self::ID_TOKEN, $basename, $m)) {
            return [];
        }

        return array_values(array_unique(array_map('strtolower', $m[0])));
    }

    private function privilegedCommand(Server $server, string $command): string
    {
        $user = trim((string) $server->ssh_user);

        if ($user === '' || $user === 'root') {
            return $command;
        }

        return 'sudo -n bash -lc '.escapeshellarg($command);
    }
}

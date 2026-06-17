<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use Illuminate\Console\Command;

/**
 * Locate which site serves a given hostname.
 *
 *   dply:fleet:domain-find example.com
 *   dply:fleet:domain-find example.com --contains       # substring match
 *   dply:fleet:domain-find .example.com --contains
 *   dply:fleet:domain-find example.com --json
 *
 * Default mode is exact-match against site_domains.hostname (the
 * hostname is normalized: lowercased, scheme/trailing-slash
 * stripped). --contains switches to substring match for "find every
 * site under example.com".
 *
 * Reports: domain, primary flag, the site, and the server it lives
 * on. Empty result exits 1.
 */
class FindFleetDomainCommand extends Command
{
    protected $signature = 'dply:fleet:domain-find
        {hostname : Hostname to find (or substring with --contains)}
        {--contains : Match every domain CONTAINING the argument as a substring}
        {--json : Output as JSON}';

    protected $description = 'Find which site serves a given hostname.';

    public function handle(): int
    {
        $needle = $this->normalize((string) $this->argument('hostname'));
        if ($needle === '') {
            $this->error('Hostname cannot be empty.');

            return self::FAILURE;
        }

        $contains = (bool) $this->option('contains');
        $query = SiteDomain::query();
        if ($contains) {
            $query->where('hostname', 'like', '%'.$this->escapeLike($needle).'%');
        } else {
            $query->where('hostname', $needle);
        }
        $domains = $query->get(['id', 'site_id', 'hostname', 'is_primary']);

        if ($domains->isEmpty()) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'hostname' => $needle,
                    'contains' => $contains,
                    'matches' => [],
                ], JSON_PRETTY_PRINT));
            } else {
                $this->info(sprintf(
                    'No sites match "%s"%s.',
                    $needle,
                    $contains ? ' (contains)' : '',
                ));
            }

            return self::FAILURE;
        }

        $sites = Site::query()
            ->whereIn('id', $domains->pluck('site_id')->unique())
            ->get(['id', 'name', 'slug', 'server_id', 'runtime'])
            ->keyBy('id');

        $servers = Server::query()
            ->whereIn('id', $sites->pluck('server_id')->filter()->unique())
            ->get(['id', 'name', 'ip_address'])
            ->keyBy('id');

        $matches = [];
        foreach ($domains as $d) {
            $site = $sites->get($d->site_id);
            if ($site === null) {
                continue;
            }
            $server = $site->server_id ? $servers->get($site->server_id) : null;
            $matches[] = [
                'hostname' => $d->hostname,
                'is_primary' => (bool) $d->is_primary,
                'site_id' => $site->id,
                'site_name' => $site->name,
                'site_slug' => $site->slug,
                'site_runtime' => $site->runtime,
                'server_id' => $server?->id,
                'server_name' => $server?->name,
                'server_ip' => $server?->ip_address,
            ];
        }
        usort($matches, fn ($a, $b) => $a['hostname'] <=> $b['hostname']);

        if ($this->option('json')) {
            $this->line(json_encode([
                'hostname' => $needle,
                'contains' => $contains,
                'count' => count($matches),
                'matches' => $matches,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Found %d match(es) for "%s"%s:',
            count($matches),
            $needle,
            $contains ? ' (contains)' : '',
        ));
        $this->newLine();

        $this->table(
            ['hostname', 'site', 'runtime', 'server', 'primary'],
            array_map(fn (array $m) => [
                $m['hostname'],
                $m['site_name'],
                $m['site_runtime'],
                $m['server_name'] ?? '—',
                $m['is_primary'] ? '★' : '',
            ], $matches),
        );

        return self::SUCCESS;
    }

    private function normalize(string $raw): string
    {
        $h = strtolower(trim($raw));
        $h = (string) preg_replace('#^https?://#', '', $h);

        return rtrim($h, '/');
    }

    private function escapeLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Support\Servers\CacheServiceNetworkExposure;
use App\Support\Servers\DatabaseEngineInfo;
use Illuminate\Support\Collection;

/**
 * Node-link "servers → services → exposure" networking map for the Networking
 * workspace, rendered with the shared {@see resources/views/components/node-graph.blade.php}
 * component (the same SVG-edge / Alpine-hover technique as the SSH access map).
 *
 * Three columns:
 *   1. Servers   — every server in the workspace (current + peers), subtitled with
 *      its private IP (or public IP) so the operator can see what's on a private net.
 *   2. Services  — each running database engine and cache service, grouped per server.
 *      This is the SPINE: services lay out top-to-bottom and everything else aligns
 *      to them. Exposed services carry a third line naming the source they're open to.
 *   3. Exposure  — two buckets: "Network-exposed" (a database has remote_access, or a
 *      cache has a managed firewall allow rule) vs "Localhost-only".
 *
 * Layout: a server is vertically centered on the block of services that run on it
 * (so a single-service box sits exactly on its service row), and the exposure buckets
 * are centered against the full spine. Coordinates are computed here so the blade
 * stays a dumb renderer and the SVG edge layer survives Livewire poll morphs without
 * JS measuring the DOM.
 */
final class ServerNetworkMap
{
    /** Node card height in px (fixed so y-coordinates are deterministic). */
    private const ROW_H = 66;

    /** Vertical gap between node cards in px. */
    private const ROW_GAP = 18;

    /** Top padding inside the map container in px. */
    private const PAD_TOP = 10;

    /**
     * SVG x anchors in 0..100 viewBox units (preserveAspectRatio="none"). These
     * MUST equal the card-column boundaries in node-graph.blade.php so the edge
     * ends land flush on the card edges: servers 0–28, services 35–65, exposure
     * 72–100, with ~7% gaps that hold the connecting curves.
     */
    private const COL_SERVERS_RIGHT = 28.0;

    private const COL_SERVICES_LEFT = 35.0;

    private const COL_SERVICES_RIGHT = 65.0;

    private const COL_EXPOSURE_LEFT = 72.0;

    public function __construct(
        private CacheServiceNetworkExposure $exposure,
    ) {}

    /**
     * @param  Collection<int, Server>  $allServers  current server + peers, in display order
     * @param  Collection<string, mixed>  $enginesByServer  ServerDatabaseEngine grouped by server_id
     * @param  Collection<string, mixed>  $databasesByServer  ServerDatabase grouped by server_id
     * @param  Collection<string, mixed>  $cachesByServer  ServerCacheService grouped by server_id
     * @return array<string, mixed>
     */
    public function build(
        Server $current,
        Collection $allServers,
        Collection $enginesByServer,
        Collection $databasesByServer,
        Collection $cachesByServer,
    ): array {
        // Build the service spine first, grouped per server, recording the
        // contiguous block of spine rows each server occupies so we can centre
        // the server card on its own services. A server with no services still
        // reserves one (blank) row so it stays visible and aligned.
        $services = [];
        $serviceY = [];        // service id => center y (px)
        $serviceServer = [];   // service id => server id
        $serviceExposed = [];  // service id => bool
        $serverBlocks = [];    // server id => [start row, row count]

        $row = 0;
        foreach ($allServers as $s) {
            $start = $row;
            $engines = $enginesByServer->get($s->id, collect());
            $dbs = $databasesByServer->get($s->id, collect());
            $caches = $cachesByServer->get($s->id, collect());

            foreach ($engines as $eng) {
                $id = 'svc:'.$s->id.':eng:'.$eng->engine;
                [$exposed, $detail, $title] = $this->engineExposure($dbs, (string) $eng->engine);
                $serviceY[$id] = $this->centerY($row);
                $serviceServer[$id] = $s->id;
                $serviceExposed[$id] = $exposed;
                $services[] = [
                    'id' => $id,
                    'label' => DatabaseEngineInfo::for($eng->engine)['label'] ?? ucfirst($eng->engine),
                    'sub' => trim((string) $eng->port).' · '.$s->name,
                    'detail' => $exposed ? $detail : null,
                    'title' => $title,
                    'mono' => true,
                    'tone' => $exposed ? 'warn' : 'default',
                    'top' => $this->topY($row),
                ];
                $row++;
            }

            foreach ($caches as $cache) {
                $id = 'svc:'.$s->id.':cache:'.$cache->id;
                [$exposed, $detail, $title] = $this->cacheExposure($cache);
                $serviceY[$id] = $this->centerY($row);
                $serviceServer[$id] = $s->id;
                $serviceExposed[$id] = $exposed;
                $services[] = [
                    'id' => $id,
                    'label' => ucfirst((string) $cache->engine),
                    'sub' => trim((string) $cache->port).' · '.$s->name,
                    'detail' => $exposed ? $detail : null,
                    'title' => $title,
                    'mono' => true,
                    'tone' => $exposed ? 'warn' : 'default',
                    'top' => $this->topY($row),
                ];
                $row++;
            }

            // Reserve a blank spine row for a server that runs nothing tracked.
            if ($row === $start) {
                $row++;
            }
            $serverBlocks[$s->id] = [$start, $row - $start];
        }

        if ($services === []) {
            return $this->empty();
        }

        $spineRows = $row;
        $height = self::PAD_TOP + $spineRows * (self::ROW_H + self::ROW_GAP);

        // Servers: centre each on its block of service rows.
        $servers = [];
        $serverY = [];
        foreach ($allServers as $s) {
            [$start, $count] = $serverBlocks[$s->id];
            $center = $this->centerY($start + ($count - 1) / 2);
            $serverY[$s->id] = $center;
            $ip = $s->private_ip_address ?: $s->ip_address;
            $servers[] = [
                'id' => 'srv:'.$s->id,
                'label' => (string) $s->name,
                'sub' => $ip ? (string) $ip : __('no IP yet'),
                'mono' => (bool) $ip,
                'tone' => $s->id === $current->id ? 'highlight' : 'default',
                'top' => (int) round($center - self::ROW_H / 2),
            ];
        }

        // Exposure buckets: only those in use, vertically centred against the spine.
        $netCount = count(array_filter($serviceExposed, fn ($e) => $e === true));
        $localCount = count(array_filter($serviceExposed, fn ($e) => $e === false));
        $buckets = [];
        if ($netCount > 0) {
            $buckets[] = [
                'id' => 'exp:network',
                'label' => __('Network-exposed'),
                'sub' => trans_choice('{1}:count service|[2,*]:count services', $netCount, ['count' => $netCount]).' · '.__('reachable off-box'),
                'mono' => false,
                'tone' => 'warn',
            ];
        }
        if ($localCount > 0) {
            $buckets[] = [
                'id' => 'exp:local',
                'label' => __('Localhost-only'),
                'sub' => trans_choice('{1}:count service|[2,*]:count services', $localCount, ['count' => $localCount]).' · '.__('not reachable off-box'),
                'mono' => false,
                'tone' => 'good',
            ];
        }

        $exposureY = [];
        $nb = count($buckets);
        $blockHeight = $nb * self::ROW_H + max(0, $nb - 1) * self::ROW_GAP;
        $startTop = max(self::PAD_TOP, ($height - $blockHeight) / 2);
        foreach ($buckets as $j => $bucket) {
            $top = $startTop + $j * (self::ROW_H + self::ROW_GAP);
            $exposureY[$bucket['id']] = $top + self::ROW_H / 2;
            $buckets[$j]['top'] = (int) round($top);
        }

        $edges = [];
        foreach ($serviceServer as $serviceId => $serverId) {
            if (! isset($serverY[$serverId], $serviceY[$serviceId])) {
                continue;
            }
            $edges[] = [
                'from' => 'srv:'.$serverId,
                'to' => $serviceId,
                'x1' => self::COL_SERVERS_RIGHT,
                'y1' => $serverY[$serverId],
                'x2' => self::COL_SERVICES_LEFT,
                'y2' => $serviceY[$serviceId],
            ];

            $bucket = $serviceExposed[$serviceId] ? 'exp:network' : 'exp:local';
            if (! isset($exposureY[$bucket])) {
                continue;
            }
            $edges[] = [
                'from' => $serviceId,
                'to' => $bucket,
                'x1' => self::COL_SERVICES_RIGHT,
                'y1' => $serviceY[$serviceId],
                'x2' => self::COL_EXPOSURE_LEFT,
                'y2' => $exposureY[$bucket],
            ];
        }

        return [
            'has_data' => true,
            'height' => $height,
            'row_h' => self::ROW_H,
            'columns' => [
                'left' => __('Servers'),
                'mid' => __('Services'),
                'right' => __('Exposure'),
            ],
            'left' => $servers,
            'mid' => $services,
            'right' => $buckets,
            'edges' => $edges,
        ];
    }

    /**
     * Exposure of a database engine: exposed when any of its databases accepts
     * remote connections. The "why" is the distinct allowed-from source(s).
     *
     * @param  Collection<int, mixed>  $dbs
     * @return array{0: bool, 1: ?string, 2: string}  [exposed, detail line, tooltip]
     */
    private function engineExposure(Collection $dbs, string $engine): array
    {
        $engineDbs = $dbs->where('engine', $engine);
        $exposedDbs = $engineDbs->where('remote_access', true);
        if ($exposedDbs->isEmpty()) {
            return [false, null, __('Bound to localhost — no database accepts remote connections.')];
        }

        $sources = $exposedDbs
            ->map(fn ($db) => trim((string) $db->allowed_from))
            ->filter()
            ->unique()
            ->values();

        $detail = match (true) {
            $sources->isEmpty() => __('open to any source'),
            $sources->count() === 1 => __('open to :cidr', ['cidr' => $sources->first()]),
            default => __('open to :count sources', ['count' => $sources->count()]),
        };

        $title = __(':open of :total databases accept remote connections (:sources).', [
            'open' => $exposedDbs->count(),
            'total' => $engineDbs->count(),
            'sources' => $sources->isEmpty() ? __('any source') : $sources->implode(', '),
        ]);

        return [true, $detail, $title];
    }

    /**
     * Exposure of a cache service: exposed when a managed firewall rule opens its
     * port. The "why" is that rule's source CIDR.
     *
     * @return array{0: bool, 1: ?string, 2: string}  [exposed, detail line, tooltip]
     */
    private function cacheExposure(mixed $cache): array
    {
        $resolved = $this->exposure->resolveExposure($cache);
        if (! ($resolved['exposed'] ?? false)) {
            return [false, null, __('Bound to localhost — no firewall rule opens this port.')];
        }

        $source = trim((string) ($resolved['rule']->source ?? ''));

        return [
            true,
            $source !== '' ? __('open to :cidr', ['cidr' => $source]) : __('open to any source'),
            __('A managed firewall rule allows this port from :source.', [
                'source' => $source !== '' ? $source : __('any source'),
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private function empty(): array
    {
        return [
            'has_data' => false,
            'height' => 0,
            'row_h' => self::ROW_H,
            'columns' => [
                'left' => __('Servers'),
                'mid' => __('Services'),
                'right' => __('Exposure'),
            ],
            'left' => [],
            'mid' => [],
            'right' => [],
            'edges' => [],
        ];
    }

    private function topY(int|float $index): int
    {
        return (int) round(self::PAD_TOP + $index * (self::ROW_H + self::ROW_GAP));
    }

    private function centerY(int|float $index): float
    {
        return self::PAD_TOP + $index * (self::ROW_H + self::ROW_GAP) + self::ROW_H / 2;
    }
}

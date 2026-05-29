@php
    /**
     * Listening-ports table for a server. Reads the parsed `ss -lntp` output
     * stamped onto $server->meta.manage_listening_ports by ServerInventoryProbeScript.
     *
     * Renders nothing when meta is empty so callers can drop the include
     * unconditionally without guarding on data.
     *
     * @var \App\Models\Server $server
     */
    $portsMeta = $server->meta['manage_listening_ports'] ?? null;
    $portRows = [];

    // Classify a bind address by reachability so the table can flag at-a-glance
    // which rows actually need a firewall rule. A process bound to 127.x.x.x or
    // [::1] cannot accept off-host connections regardless of UFW state, so a
    // firewall rule on that port would be misleading (it would imply external
    // reachability that the daemon itself refuses). RFC1918 ranges are called
    // out separately because they're commonly the cloud's internal network —
    // exposed within a VPC but not to the public internet.
    $scopeOf = static function (string $bind): array {
        $b = trim($bind);
        // Strip trailing interface suffix like "127.0.0.53%lo" before testing.
        $bareB = (string) preg_replace('/%[A-Za-z0-9_-]+$/', '', $b);
        $isLoopback = $bareB === '127.0.0.1'
            || $bareB === '::1'
            || $bareB === '[::1]'
            || str_starts_with($bareB, '127.');
        if ($isLoopback) {
            return [
                'kind' => 'loopback',
                'label' => __('loopback · not exposed'),
                'tip' => __('Bound to the loopback interface. The daemon refuses connections from outside the host, so no firewall rule is needed (or would help).'),
                'classes' => 'bg-brand-sand/50 text-brand-mist ring-1 ring-brand-ink/10',
            ];
        }
        $isAnyInterface = $bareB === '0.0.0.0' || $bareB === '*' || $bareB === '::' || $bareB === '[::]';
        if ($isAnyInterface) {
            return [
                'kind' => 'public',
                'label' => __('all interfaces · needs firewall rule'),
                'tip' => __('Bound to every interface. Anyone with network access to the host can connect unless a firewall rule blocks the port.'),
                'classes' => 'bg-amber-50 text-amber-900 ring-1 ring-amber-200',
            ];
        }
        // Strip IPv6 brackets for the private-range check.
        $bareIp = trim($bareB, '[]');
        $isPrivate = (bool) preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|169\.254\.|fc|fd|fe80:)/i', $bareIp);
        if ($isPrivate) {
            return [
                'kind' => 'private',
                'label' => __('private network'),
                'tip' => __('Bound to a private/internal address. Reachable from the same VPC or LAN but not the public internet; firewall rule depends on your network model.'),
                'classes' => 'bg-sky-50 text-sky-800 ring-1 ring-sky-200',
            ];
        }
        // Specific routable IP — treated as publicly reachable on that NIC.
        return [
            'kind' => 'public',
            'label' => __('public interface · needs firewall rule'),
            'tip' => __('Bound to a specific routable address. Reachable from outside the host unless a firewall rule blocks the port.'),
            'classes' => 'bg-amber-50 text-amber-900 ring-1 ring-amber-200',
        ];
    };

    if (! empty($portsMeta) && is_string($portsMeta)) {
        foreach (explode("\n", $portsMeta) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // ss -lntpH columns: State Recv-Q Send-Q Local Address:Port Peer Address:Port Process
            $cols = preg_split('/\s+/', $line);
            if (! is_array($cols) || count($cols) < 5) {
                continue;
            }
            $local = $cols[3] ?? '';
            if (! preg_match('/(\S+):(\d+)$/', $local, $m)) {
                continue;
            }
            $bind = $m[1];
            $port = $m[2];
            $proc = '';
            if (! empty($cols[5]) && preg_match('/users:\(\("([^"]+)"/', implode(' ', array_slice($cols, 5)), $pm)) {
                $proc = $pm[1];
            }
            $portRows[] = ['port' => $port, 'bind' => $bind, 'process' => $proc, 'scope' => $scopeOf($bind)];
        }
        usort($portRows, fn ($a, $b) => (int) $a['port'] <=> (int) $b['port']);
    }
@endphp

@if (! empty($portRows))
    <div class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-signal class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Ports') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Listening ports') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('From `ss -lntp` on the host. Use this to sanity-check which process is bound where before adding or tightening firewall rules.') }}
                </p>
            </div>
            @if (method_exists($this, 'refreshListeningPorts'))
                <button
                    type="button"
                    wire:click="refreshListeningPorts"
                    wire:loading.attr="disabled"
                    wire:target="refreshListeningPorts"
                    class="ml-auto inline-flex shrink-0 items-center justify-center gap-1.5 self-start rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <x-heroicon-o-arrow-path wire:loading.remove wire:target="refreshListeningPorts" class="h-3.5 w-3.5" />
                    <span wire:loading wire:target="refreshListeningPorts" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                        <x-spinner variant="forest" size="sm" />
                    </span>
                    <span wire:loading.remove wire:target="refreshListeningPorts">{{ __('Refresh ports') }}</span>
                    <span wire:loading wire:target="refreshListeningPorts">{{ __('Reading…') }}</span>
                </button>
            @endif
        </div>
        <div class="px-6 py-6 sm:px-7">
        <div class="overflow-hidden rounded-xl border border-brand-ink/10">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-brand-sand/30 text-left text-[11px] uppercase tracking-wide text-brand-mist">
                    <tr>
                        <th class="px-4 py-2 font-semibold">{{ __('Port') }}</th>
                        <th class="px-4 py-2 font-semibold">{{ __('Process') }}</th>
                        <th class="px-4 py-2 font-semibold">{{ __('Bind address') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/5 bg-white">
                    @foreach ($portRows as $row)
                        <tr>
                            <td class="px-4 py-1.5 font-mono text-xs text-brand-ink">{{ $row['port'] }}</td>
                            <td class="px-4 py-1.5 font-mono text-xs text-brand-ink">{{ $row['process'] ?: '—' }}</td>
                            <td class="px-4 py-1.5 text-xs text-brand-moss">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-mono">{{ $row['bind'] }}</span>
                                    <span
                                        class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $row['scope']['classes'] }}"
                                        title="{{ $row['scope']['tip'] }}"
                                    >{{ $row['scope']['label'] }}</span>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        </div>
    </div>
@endif

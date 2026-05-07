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
            $portRows[] = ['port' => $port, 'bind' => $bind, 'process' => $proc];
        }
        usort($portRows, fn ($a, $b) => (int) $a['port'] <=> (int) $b['port']);
    }
@endphp

@if (! empty($portRows))
    <div class="dply-card overflow-hidden p-6 sm:p-8">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
            <div class="min-w-0">
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Listening ports') }}</h3>
                <p class="mt-1 text-sm text-brand-moss">
                    {{ __('From `ss -lntp` on the host. Use this to sanity-check which process is bound where before adding or tightening firewall rules.') }}
                </p>
            </div>
            @if (method_exists($this, 'refreshListeningPorts'))
                <button
                    type="button"
                    wire:click="refreshListeningPorts"
                    wire:loading.attr="disabled"
                    wire:target="refreshListeningPorts"
                    class="inline-flex shrink-0 items-center justify-center gap-1.5 self-start rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
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
        <div class="mt-4 overflow-hidden rounded-xl border border-brand-ink/10">
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
                            <td class="px-4 py-1.5 font-mono text-xs text-brand-moss">{{ $row['bind'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

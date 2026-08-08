{{-- =====================================================================
     Shared "live on-disk TLS certificates" card. One cross-engine SSH
     sweep (Let's Encrypt + Caddy local store + every per-engine ssl dir)
     with real openssl-parsed expiry, sorted soonest-first. The sweep runs
     async in ScanServerLiveCertsJob; this card polls for the result via
     pollLiveCerts while a scan is in flight, so SSH never runs in-request.

     Backed by App\Livewire\Servers\Concerns\LoadsLiveServerCerts — the host
     supplies the $liveCerts* state. Optional overrides:
       $liveCertsTitle, $liveCertsDescription, $liveCertsWrapperClass
     ===================================================================== --}}
@php
    $liveCertsTitle ??= __('Live certificates on server');
    $liveCertsDescription ??= __('Actual certs on disk — including Caddy automatic-HTTPS certs that aren\'t in the managed records — with real expiry from openssl.');
    $liveCertsWrapperClass ??= 'dply-card overflow-hidden';
@endphp
@php
    // Head tone + count pill track the worst urgency once the sweep lands, so
    // an expiring cert is visible without opening the table.
    $liveCertsHeadCounts = ['expired' => 0, 'danger' => 0, 'warn' => 0, 'ok' => 0, 'unknown' => 0];
    foreach (($liveCertsLoaded ? $liveCerts : []) as $liveCertRow) {
        $liveCertsHeadUrgency = (string) ($liveCertRow['urgency'] ?? 'unknown');
        $liveCertsHeadCounts[$liveCertsHeadUrgency] = ($liveCertsHeadCounts[$liveCertsHeadUrgency] ?? 0) + 1;
    }
    $liveCertsHeadTone = match (true) {
        $liveCertsHeadCounts['expired'] > 0 => 'danger',
        $liveCertsHeadCounts['danger'] > 0 || $liveCertsHeadCounts['warn'] > 0 => 'amber',
        default => null,
    };
@endphp
<section class="{{ $liveCertsWrapperClass }}" wire:init="loadLiveCerts">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-lock-closed"
        :title="$liveCertsTitle"
        :tone="$liveCertsHeadTone"
        :count="$liveCertsLoaded && $liveCerts !== []
            ? trans_choice('{1} :count cert|[2,*] :count certs', count($liveCerts), ['count' => count($liveCerts)])
            : null"
        :note="$liveCertsDescription
            .($liveCertsScannedAtIso ? ' '.__('Scanned :time', ['time' => \Illuminate\Support\Carbon::parse($liveCertsScannedAtIso)->diffForHumans()]) : '')"
        class="border-b border-brand-ink/10"
    >
        <x-slot:actions>
            <button
                type="button"
                wire:click="refreshLiveCerts"
                wire:loading.attr="disabled"
                wire:target="refreshLiveCerts,loadLiveCerts"
                class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-[11px] font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="refreshLiveCerts,loadLiveCerts" class="inline-flex">
                    <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                </span>
                <span wire:loading wire:target="refreshLiveCerts,loadLiveCerts" class="inline-flex">
                    <x-spinner class="h-3.5 w-3.5" />
                </span>
                {{ __('Rescan') }}
            </button>
        </x-slot:actions>
    </x-workspace-panel-head>

    @if ($liveCertsError)
        <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 border-b border-rose-200 bg-rose-50/70 px-4 py-2 text-[11px] text-rose-900 sm:px-5">
            <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            {{ $liveCertsError }}
        </p>
    @endif

    @if ($liveCertsTimedOut)
        {{-- Poll budget exhausted (no result cached in time — usually a stopped worker).
             Stop spinning and offer an explicit retry instead of polling forever. --}}
        <div class="px-4 py-6 text-center text-xs text-brand-moss sm:px-5">
            <x-heroicon-o-clock class="mx-auto h-5 w-5 text-brand-mist" aria-hidden="true" />
            <p class="mt-2 text-sm font-semibold text-brand-ink">{{ __('Scan didn\'t return in time') }}</p>
            <p class="mt-1">{{ __('The certificate scan was queued but no result came back. The scan worker may be busy or offline.') }}</p>
            @if (! empty($liveCertsProgress))
                {{-- Show how far the sweep got before the poll budget ran out. --}}
                <div class="mx-auto mt-3 max-h-40 max-w-xl overflow-y-auto rounded-md border border-brand-ink/10 bg-brand-ink/[0.03] px-3 py-2 text-left font-mono text-[11px] leading-relaxed text-brand-ink/70">
                    @foreach ($liveCertsProgress as $entry)
                        <div class="break-all">{{ $entry['line'] ?? '' }}</div>
                    @endforeach
                </div>
            @endif
            <button
                type="button"
                wire:click="refreshLiveCerts"
                wire:loading.attr="disabled"
                wire:target="refreshLiveCerts"
                class="mt-3 inline-flex h-7 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 text-[11px] font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:opacity-60"
            >
                <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ __('Retry scan') }}
            </button>
        </div>
    @elseif (! $liveCertsLoaded)
        {{-- Scanning: poll until the job caches a result (or the budget runs out and
             pollLiveCerts flips to the timed-out state above). The worker's captured
             frames are replayed once the result lands (below), so the progress
             itself isn't animated here — a ~1.7s scan is too fast to animate via
             polling anyway. Just the status line and a stub of the table. --}}
        <div @if ($liveCertsScanning) wire:poll.{{ $this->liveCertsPollInterval() }}s="pollLiveCerts" @endif aria-busy="true" aria-live="polite">
            @php $bar = 'animate-pulse rounded bg-brand-ink/10'; @endphp
            <p class="flex items-center gap-2 border-b border-brand-ink/10 px-4 py-2 text-[11px] text-brand-moss sm:px-5">
                <x-spinner class="h-3.5 w-3.5 shrink-0" />
                {{ __('Scanning certificates on the server…') }}
            </p>
            {{-- Stub the result table rather than leaving the card empty under
                 the spinner — the sweep lands into exactly this shape. --}}
            <div class="px-4 py-3.5 sm:px-5" aria-hidden="true">
                <div class="overflow-hidden rounded-xl border border-brand-ink/10">
                    <div class="flex items-center gap-3 bg-brand-sand/30 px-3 py-2">
                        @foreach ([28, 20, 18, 12, 14] as $width)
                            <span class="h-2 shrink-0 {{ $bar }}" style="width: {{ $width * 4 }}px;"></span>
                        @endforeach
                    </div>
                    <div class="divide-y divide-brand-ink/5 bg-white">
                        @foreach (range(1, 4) as $row)
                            <div class="flex items-center gap-3 px-3 py-2">
                                @foreach ([28, 20, 18, 12, 14] as $width)
                                    <span class="h-2.5 shrink-0 {{ $bar }}" style="width: {{ $width * 4 }}px;"></span>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @else
        {{-- Replay the worker's captured frames, then fade in the result — so the
             steps are always visible no matter how fast the scan finished. --}}
        <x-replay-log :frames="$liveCertsProgress">
            @if ($liveCertsUnreadable)
                <div class="px-4 py-6 text-center text-xs text-brand-moss sm:px-5">
                    {{ __('Could not run the cert scan over SSH. Check that the deploy user has passwordless sudo for `find` + `openssl`.') }}
                </div>
            @elseif (empty($liveCerts))
                <div class="px-4 py-6 text-center text-xs text-brand-moss sm:px-5">
                    <x-heroicon-o-shield-check class="mx-auto h-5 w-5 text-brand-mist" aria-hidden="true" />
                    <p class="mt-2">{{ __('No server certificates found under the scanned paths.') }}</p>
                </div>
            @else
                @php
            $liveUrgencyCounts = ['expired' => 0, 'danger' => 0, 'warn' => 0, 'ok' => 0, 'unknown' => 0];
            foreach ($liveCerts as $c) {
                $u = (string) ($c['urgency'] ?? 'unknown');
                $liveUrgencyCounts[$u] = ($liveUrgencyCounts[$u] ?? 0) + 1;
            }
        @endphp
        <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 bg-white px-4 py-2 text-[11px] sm:px-5">
            <span class="text-brand-moss">{{ __(':n cert(s)', ['n' => count($liveCerts)]) }}</span>
            @if ($liveUrgencyCounts['expired'] > 0)
                <span class="inline-flex items-center gap-1 rounded-full bg-rose-100 px-2 py-0.5 font-semibold text-rose-800">
                    <x-heroicon-o-x-circle class="h-3 w-3" /> {{ $liveUrgencyCounts['expired'] }} {{ __('expired') }}
                </span>
            @endif
            @if ($liveUrgencyCounts['danger'] > 0)
                <span class="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 font-semibold text-rose-700">
                    <x-heroicon-o-exclamation-triangle class="h-3 w-3" /> {{ $liveUrgencyCounts['danger'] }} {{ __('< 14d') }}
                </span>
            @endif
            @if ($liveUrgencyCounts['warn'] > 0)
                <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-800">
                    <x-heroicon-o-clock class="h-3 w-3" /> {{ $liveUrgencyCounts['warn'] }} {{ __('< 60d') }}
                </span>
            @endif
            @if ($liveUrgencyCounts['ok'] > 0)
                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 font-semibold text-emerald-700">
                    <x-heroicon-o-check-circle class="h-3 w-3" /> {{ $liveUrgencyCounts['ok'] }} {{ __('healthy') }}
                </span>
            @endif
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-xs">
                <thead class="bg-brand-sand/30 text-brand-moss">
                    <tr>
                        <th class="px-3 py-2 font-semibold">{{ __('Path') }}</th>
                        <th class="px-3 py-2 font-semibold">{{ __('Subject') }}</th>
                        <th class="px-3 py-2 font-semibold">{{ __('Issuer') }}</th>
                        <th class="px-3 py-2 font-semibold">{{ __('Engine') }}</th>
                        <th class="px-3 py-2 font-semibold text-right">{{ __('Expires') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/5 bg-white">
                    @foreach ($liveCerts as $cert)
                        @php
                            $urgency = (string) ($cert['urgency'] ?? 'unknown');
                            $days = $cert['days_until_expiry'] ?? null;
                        @endphp
                        <tr>
                            <td class="break-all px-3 py-2 font-mono text-[11px] text-brand-ink">{{ $cert['path'] }}</td>
                            <td class="max-w-[14rem] px-3 py-2 text-brand-moss">{{ $cert['subject'] ?: '—' }}</td>
                            <td class="max-w-[12rem] px-3 py-2 text-brand-moss">{{ $cert['issuer'] ?: '—' }}</td>
                            <td class="px-3 py-2">
                                <span class="inline-flex items-center rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ $cert['engine_hint'] ?? 'other' }}</span>
                            </td>
                            <td class="px-3 py-2 text-right">
                                @if ($cert['error'])
                                    <span class="text-[11px] text-rose-700" title="{{ $cert['error'] }}">—</span>
                                @else
                                    <span @class([
                                        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1',
                                        'bg-rose-100 text-rose-900 ring-rose-200' => $urgency === 'expired',
                                        'bg-rose-50 text-rose-700 ring-rose-200' => $urgency === 'danger',
                                        'bg-amber-50 text-amber-800 ring-amber-200' => $urgency === 'warn',
                                        'bg-emerald-50 text-emerald-700 ring-emerald-200' => $urgency === 'ok',
                                        'bg-brand-sand/40 text-brand-moss ring-brand-ink/10' => $urgency === 'unknown',
                                    ])>
                                        @if ($urgency === 'expired')
                                            {{ __('expired :n d ago', ['n' => abs((int) $days)]) }}
                                        @elseif ($days !== null)
                                            {{ __(':n d', ['n' => (int) $days]) }}
                                        @else
                                            —
                                        @endif
                                    </span>
                                    @if (! empty($cert['not_after']))
                                        <p class="mt-0.5 text-[10px] text-brand-mist tabular-nums">{{ $cert['not_after'] }}</p>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
            @endif
        </x-replay-log>
    @endif
</section>

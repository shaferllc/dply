{{-- Drift between what this engine actually holds and what dply tracks.
     Populated by a wire:init-deferred scan (never on first render — the scan
     is SSH-bound) and read from server.meta, so re-renders are free.

     Two directions, both deliberately manual: adopting records a database dply
     did not create, and forgetting removes a row whose database is gone. A
     scan never acts on its own findings, because an engine it could not reach
     reports nothing rather than "empty". --}}
@php
    $engineUntracked = collect($untrackedDatabases)->where('engine', $engine)->values();
    $engineMissing = collect($missingDatabases)
        ->filter(fn ($m) => \App\Support\Servers\DatabaseWorkspaceEngines::label($engine) === $m['engine'])
        ->values();
@endphp

@if ($inventoryWarning !== '')
    <div class="mt-4 rounded-lg border border-amber-200/70 bg-amber-50 px-3 py-2 text-xs text-amber-900">
        {{ $inventoryWarning }}
    </div>
@endif

@if ($engineUntracked->isNotEmpty() || $engineMissing->isNotEmpty())
    <div class="mt-4 overflow-hidden rounded-xl border border-brand-ink/10">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-brand-ink/10 bg-brand-sand/20 px-4 py-2.5">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Not in sync with this server') }}</p>
                @if ($inventoryScannedAt)
                    <p class="mt-0.5 text-2xs text-brand-moss">
                        {{ __('Scanned :when', ['when' => \Illuminate\Support\Carbon::parse($inventoryScannedAt)->diffForHumans()]) }}
                    </p>
                @endif
            </div>
            <button type="button" wire:click="rescanDatabaseInventory" wire:loading.attr="disabled" wire:target="rescanDatabaseInventory"
                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-moss shadow-sm hover:bg-brand-sand/40 disabled:opacity-60">
                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" aria-hidden="true" />
                {{ __('Rescan') }}
            </button>
        </div>

        <ul class="divide-y divide-brand-ink/5">
            @foreach ($engineUntracked as $row)
                <li class="flex flex-wrap items-center gap-3 px-4 py-2.5" wire:key="untracked-{{ $engine }}-{{ md5($row['name']) }}">
                    <div class="min-w-0 flex-1">
                        <p class="font-mono text-sm font-semibold text-brand-ink">{{ $row['name'] }}</p>
                        <p class="mt-0.5 text-xs text-brand-moss">
                            {{ __('On the server but not tracked by dply. Adopting records it — dply will not hold its password, so environment wiring stays off until you rotate it.') }}
                        </p>
                    </div>
                    <button type="button"
                        wire:click="adoptUntrackedDatabase(@js($row['engine']), @js($row['name']))"
                        wire:loading.attr="disabled"
                        wire:target="adoptUntrackedDatabase"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-brand-forest px-2.5 py-1 text-xs font-semibold text-white shadow-sm hover:bg-brand-forest/90 disabled:opacity-60">
                        <x-heroicon-o-plus class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ __('Track it') }}
                    </button>
                </li>
            @endforeach

            @foreach ($engineMissing as $row)
                <li class="flex flex-wrap items-center gap-3 px-4 py-2.5" wire:key="missing-{{ $row['id'] }}">
                    <div class="min-w-0 flex-1">
                        <p class="font-mono text-sm font-semibold text-brand-ink">{{ $row['name'] }}</p>
                        <p class="mt-0.5 text-xs text-brand-moss">
                            {{ __('Tracked by dply but no longer on the server. Removing clears the record only — nothing on the server is touched.') }}
                        </p>
                    </div>
                    <button type="button"
                        wire:click="forgetMissingDatabase(@js($row['id']))"
                        wire:confirm="{{ __('Remove dply\'s record of :name? The database is already gone from the server; this only clears the row, including its stored credentials and any site link.', ['name' => $row['name']]) }}"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-2.5 py-1 text-xs font-semibold text-rose-700 shadow-sm hover:bg-rose-50">
                        <x-heroicon-o-trash class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ __('Remove record') }}
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
@endif

@php
    // One table, both families. A provider token and a backup destination are
    // different rows in different tables, but from the reader's side they answer
    // the same question — what have we handed to a third party, and does it
    // still work? The component's rows() projection makes them comparable.
    $filters = [
        ['id' => 'all', 'label' => __('All'), 'icon' => 'heroicon-o-key'],
        ['id' => 'compute', 'label' => __('Compute'), 'icon' => 'heroicon-o-cpu-chip'],
        ['id' => 'dns', 'label' => __('DNS'), 'icon' => 'heroicon-o-globe-alt'],
        ['id' => 'cdn', 'label' => __('CDN'), 'icon' => 'heroicon-o-bolt'],
        ['id' => 'storage', 'label' => __('Storage'), 'icon' => 'heroicon-o-circle-stack'],
        ['id' => 'attention', 'label' => __('Needs attention'), 'tone' => 'alert', 'icon' => 'heroicon-o-exclamation-triangle'],
    ];

    $statusChip = fn (string $status): string => match ($status) {
        'ok' => 'border-brand-sage/35 bg-brand-sage/15 text-brand-forest',
        'bad' => 'border-rose-200 bg-rose-50 text-rose-700',
        'warn' => 'border-amber-200 bg-amber-50 text-amber-900',
        default => 'border-brand-ink/10 bg-brand-sand/50 text-brand-moss',
    };

    $th = 'px-3 py-1.5 text-left text-2xs font-semibold uppercase tracking-wide text-brand-mist sm:px-4';
    $td = 'px-3 py-2 align-middle sm:px-4';

    // Row actions. whitespace-nowrap because "Re-check" was breaking across two
    // lines and making the row twice as tall as its neighbours.
    $act = 'inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border px-2 text-xs font-semibold shadow-sm transition-colors disabled:cursor-not-allowed disabled:opacity-50';
    $actNeutral = $act.' border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40';
    $actDanger = $act.' border-rose-200 bg-white text-rose-700 hover:bg-rose-50';
    $actIcon = 'h-3.5 w-3.5 shrink-0 opacity-90';
@endphp

<x-livewire-validation-errors />

{{-- In the org shell the trail is hoisted above the whole box (see index.blade.php);
     here it only renders on the standalone (non-shell) surface. --}}
@if (empty($useOrgShell))
    <x-breadcrumb-trail doc-route="docs.connect-provider" :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Settings'), 'href' => route('settings.profile'), 'icon' => 'cog-6-tooth'],
        ['label' => __('Credentials'), 'icon' => 'key'],
    ]" />
@endif

{{-- OAuth round trips land back here, so this is the only place their outcome
     can be reported. Without it a successful "Connect Dropbox" returned
     silently and looked like nothing happened. --}}
@if (session('success') || session('error'))
    <div @class([
        'px-5 py-4 sm:px-6' => empty($useOrgShell),
        'border-b border-brand-ink/10 px-5 py-4 sm:px-6' => ! empty($useOrgShell),
    ])>
        <x-alert :tone="session('error') ? 'danger' : 'success'">
            {{ session('error') ?: session('success') }}
        </x-alert>
    </div>
@endif

{{-- Filters act on YOUR rows. The capability tabs they replace filtered the
     catalog, so "DNS" hid providers you hadn't connected either way. Chips with
     nothing behind them are hidden rather than shown at zero. --}}
<section aria-label="{{ __('Filter credentials') }}" @class([
    'flex flex-wrap items-center gap-1.5 border-b border-brand-ink/10 bg-brand-cream/40 px-3 py-2 sm:px-4' => ! empty($useOrgShell),
    'flex flex-wrap items-center gap-1.5 rounded-xl border border-brand-ink/10 bg-white p-2' => empty($useOrgShell),
])>
    @foreach ($filters as $chip)
        @php $n = $counts[$chip['id']] ?? 0; @endphp
        @continue($n === 0 && $chip['id'] !== 'all')
        <button
            type="button"
            wire:click="setFilter('{{ $chip['id'] }}')"
            @class([
                'inline-flex h-6 items-center gap-1.5 rounded-md border px-2 text-xs font-semibold transition-colors',
                'border-brand-ink bg-brand-ink text-brand-cream' => $filter === $chip['id'],
                'border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => $filter !== $chip['id'] && ($chip['tone'] ?? '') !== 'alert',
                'border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100' => $filter !== $chip['id'] && ($chip['tone'] ?? '') === 'alert',
            ])
        >
            <x-dynamic-component :component="$chip['icon']" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            {{ $chip['label'] }}
            <span @class([
                'font-mono tabular-nums',
                'opacity-70' => $filter === $chip['id'],
                'text-brand-mist' => $filter !== $chip['id'] && ($chip['tone'] ?? '') !== 'alert',
            ])>{{ $n }}</span>
        </button>
    @endforeach
</section>

@if ($rows === [])
    <div @class([
        'px-3 py-10 text-center sm:px-4' => ! empty($useOrgShell),
        'rounded-2xl border border-brand-ink/10 bg-white px-6 py-10 text-center' => empty($useOrgShell),
    ])>
        <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
            <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
        </span>
        @if ($filter !== 'all')
            <p class="mt-3 text-sm font-medium text-brand-ink">{{ __('Nothing matches this filter.') }}</p>
            <button type="button" wire:click="setFilter('all')" class="mt-2 text-xs font-semibold text-brand-sage hover:text-brand-ink">
                {{ __('Show all credentials') }} →
            </button>
        @else
            <p class="mt-3 text-sm font-medium text-brand-ink">{{ __('No credentials yet.') }}</p>
            <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-mist">
                {{ __('Connect a provider to give dply an API token, or add a backup destination for your database dumps. Both are encrypted before they hit disk.') }}
            </p>
            <div class="mt-3 flex flex-wrap justify-center gap-2">
                <button type="button" x-on:click="$dispatch('open-add-provider-credential-modal')" class="inline-flex h-6 items-center gap-1 rounded-lg bg-brand-ink px-2.5 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest">
                    <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ __('Connect a provider') }}
                </button>
                <button type="button" wire:click="openStorageModal" class="inline-flex h-6 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                    <x-heroicon-o-cloud-arrow-up class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ __('Add backup storage') }}
                </button>
            </div>
        @endif
    </div>
@else
    <div @class(['overflow-x-auto', 'rounded-2xl border border-brand-ink/10 bg-white' => empty($useOrgShell)])>
        <table class="w-full text-sm">
            <thead class="bg-brand-sand/35">
                <tr>
                    <th class="{{ $th }}">{{ __('Provider') }}</th>
                    <th class="{{ $th }}">{{ __('Name') }}</th>
                    <th class="{{ $th }}">{{ __('Used for') }}</th>
                    <th class="{{ $th }}">{{ __('Status') }}</th>
                    <th class="{{ $th }} text-right"><span class="sr-only">{{ __('Actions') }}</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-brand-ink/10">
                @foreach ($rows as $row)
                    <tr class="transition-colors hover:bg-brand-sand/15" wire:key="cred-{{ $row['kind'] }}-{{ $row['id'] }}">
                        <td class="{{ $td }}">
                            <span class="flex items-center gap-2">
                                <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-brand-sand/45 text-brand-forest ring-1 ring-brand-ink/10">
                                    <x-credentials-provider-icon :provider="$row['provider']" class="h-3.5 w-3.5" />
                                </span>
                                <span class="truncate text-brand-ink">{{ $row['providerLabel'] }}</span>
                            </span>
                        </td>
                        <td class="{{ $td }} font-medium text-brand-ink">{{ $row['name'] }}</td>
                        <td class="{{ $td }} text-xs text-brand-moss">{{ $row['usedFor'] }}</td>
                        <td class="{{ $td }}">
                            <span class="inline-flex items-center rounded-md border px-1.5 py-0.5 text-2xs font-semibold {{ $statusChip($row['status']) }}">
                                {{ $row['statusLabel'] }}
                            </span>
                            {{-- The provider's own words. validation_error has always
                                 been stored and counted, and never shown. --}}
                            @if ($row['error'])
                                <span class="mt-0.5 block max-w-md text-xs leading-relaxed text-brand-moss">{{ $row['error'] }}</span>
                            @endif
                        </td>
                        <td class="{{ $td }} text-right">
                            <div class="inline-flex flex-nowrap items-center gap-1.5">
                                @if ($row['kind'] === 'token')
                                    @if ($this->canVerifyCredentialProvider($row['provider']))
                                        <button
                                            type="button"
                                            wire:click="verifyCredential('{{ $row['id'] }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="verifyCredential('{{ $row['id'] }}')"
                                            class="{{ $actNeutral }}"
                                        >
                                            <span wire:loading.remove wire:target="verifyCredential('{{ $row['id'] }}')" class="inline-flex items-center gap-1">
                                                <x-heroicon-o-arrow-path class="{{ $actIcon }}" aria-hidden="true" />
                                                {{ __('Re-check') }}
                                            </span>
                                            <span wire:loading wire:target="verifyCredential('{{ $row['id'] }}')" class="inline-flex items-center gap-1">
                                                <x-spinner size="sm" />
                                                {{ __('Checking…') }}
                                            </span>
                                        </button>
                                    @endif
                                    <button
                                        type="button"
                                        x-on:click="$dispatch('open-add-provider-credential-modal', { provider: @js($row['provider']) })"
                                        class="{{ $actNeutral }}"
                                    >
                                        <x-heroicon-o-cog-6-tooth class="{{ $actIcon }}" aria-hidden="true" />
                                        {{ __('Manage') }}
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="promptDestroyCredential('{{ $row['id'] }}')"
                                        class="{{ $actDanger }}"
                                    >
                                        <x-heroicon-o-trash class="{{ $actIcon }}" aria-hidden="true" />
                                        {{ __('Remove') }}
                                    </button>
                                @else
                                    <button
                                        type="button"
                                        wire:click="editDestination('{{ $row['id'] }}')"
                                        class="{{ $actNeutral }}"
                                    >
                                        <x-heroicon-o-pencil-square class="{{ $actIcon }}" aria-hidden="true" />
                                        {{ __('Edit') }}
                                    </button>
                                    <a
                                        href="{{ route('backups.storage') }}"
                                        wire:navigate
                                        class="{{ $actNeutral }}"
                                    >
                                        <x-heroicon-o-chart-bar class="{{ $actIcon }}" aria-hidden="true" />
                                        {{ __('Usage') }}
                                    </a>
                                    <button
                                        type="button"
                                        wire:click="promptDeleteDestination('{{ $row['id'] }}')"
                                        class="{{ $actDanger }}"
                                    >
                                        <x-heroicon-o-trash class="{{ $actIcon }}" aria-hidden="true" />
                                        {{ __('Remove') }}
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

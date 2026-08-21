@php
    use App\Modules\Cache\Models\ManagedCache;

    // No provisioning state on the shared tier: a cache is a row plus a grant,
    // so creation is synchronous and it is active or nothing. The dedicated
    // tier is the one that waits on a cluster.
    $statusTone = [
        ManagedCache::STATUS_ACTIVE => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        ManagedCache::STATUS_PROVISIONING => 'bg-brand-sand/55 text-brand-moss ring-brand-ink/10',
        ManagedCache::STATUS_FAILED => 'bg-red-100 text-red-700 ring-red-200',
        ManagedCache::STATUS_DELETING => 'bg-brand-sand/55 text-brand-moss ring-brand-ink/10',
    ];

    $bytes = function (int $value): string {
        if ($value < 1024) {
            return $value.' B';
        }
        foreach (['KiB', 'MiB', 'GiB'] as $index => $unit) {
            $scaled = $value / (1024 ** ($index + 1));
            if ($scaled < 1024 || $unit === 'GiB') {
                return number_format($scaled, $scaled < 10 ? 1 : 0).' '.$unit;
            }
        }
        return $value.' B';
    };

    // Fullness reads as a verdict, not a percentage: at quota, writes are
    // REJECTED rather than evicted, so nearing it means the app is about to
    // start failing rather than merely slowing down.
    $meterTone = fn (float $f): string => match (true) {
        $f >= 0.9 => 'bg-brand-rust',
        $f >= 0.7 => 'bg-brand-gold',
        default => 'bg-brand-sage',
    };
@endphp

<div class="contents">
    <x-workspace-nav />

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 sm:py-8">
        <x-breadcrumb-trail :items="$breadcrumbs" />

        <x-profile-shell
            dense
            :title="__('Caches')"
            :description="__('A shared cache your apps can actually coordinate through — so ShouldBeUnique, WithoutOverlapping and RateLimited stop silently doing nothing on multi-container runtimes.')"
            icon="heroicon-o-bolt"
        >
            <x-slot:actions>
                @if ($canManage && $caches->isNotEmpty() && ! $atLimit)
                    <button
                        type="button"
                        wire:click="startCreate"
                        class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-plus class="h-4 w-4" aria-hidden="true" />
                        {{ __('New cache') }}
                    </button>
                @endif
            </x-slot:actions>

            {{-- The one-time secret. Rendered as a banner rather than a modal
                 so a mis-click cannot dismiss the only copy that will ever
                 exist — dply stores a hash and genuinely cannot show it again. --}}
            @if ($revealedSecret !== null)
                <div class="mb-6 rounded-2xl border border-brand-gold/40 bg-brand-gold/10 p-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-brand-ink">{{ __('Copy this secret now') }}</p>
                            <p class="mt-1 text-sm text-brand-moss">
                                {{ __('It is stored as a hash and cannot be shown again. Losing it means minting a new credential, not recovering this one.') }}
                            </p>
                            <pre class="mt-3 overflow-x-auto rounded-xl bg-brand-ink px-3 py-2 text-xs text-brand-cream"><code>AWS_SECRET_ACCESS_KEY={{ $revealedSecret }}</code></pre>
                        </div>
                        <button type="button" wire:click="dismissSecret" class="shrink-0 rounded-lg p-1 text-brand-moss hover:text-brand-ink">
                            <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                            <span class="sr-only">{{ __('Dismiss') }}</span>
                        </button>
                    </div>
                </div>
            @endif

            @if ($caches->isEmpty())
                <div class="rounded-2xl border border-dashed border-brand-ink/15 bg-brand-sand/20 px-6 py-12 text-center">
                    <x-heroicon-o-bolt class="mx-auto h-8 w-8 text-brand-sage" aria-hidden="true" />
                    <h3 class="mt-3 text-base font-semibold text-brand-ink">{{ __('No caches yet') }}</h3>
                    <p class="mx-auto mt-2 max-w-xl text-sm text-brand-moss">
                        {{ __('A cache is free, and it is what makes locks work. On a function or a multi-replica container the default cache store is per-container, so ShouldBeUnique and WithoutOverlapping quietly do nothing.') }}
                    </p>
                    @if ($canManage && ! $atLimit)
                        <button
                            type="button"
                            wire:click="startCreate"
                            class="mt-5 inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                        >
                            <x-heroicon-o-plus class="h-4 w-4" aria-hidden="true" />
                            {{ __('Create a cache') }}
                        </button>
                    @endif
                </div>
            @else
                <div class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white">
                    <table class="min-w-full divide-y divide-brand-ink/10">
                        <thead class="bg-brand-sand/30">
                            <tr>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Name') }}</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Tier') }}</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Used') }}</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Keys') }}</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Status') }}</th>
                                <th scope="col" class="px-4 py-3"><span class="sr-only">{{ __('Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/5">
                            @foreach ($caches as $cache)
                                @php
                                    $u = $usage[$cache->id] ?? $emptyUsage;
                                    $quota = $cache->quotaBytes();
                                    $fraction = $u->fractionOf($quota);
                                @endphp
                                <tr class="transition-colors hover:bg-brand-sand/20">
                                    <td class="px-4 py-3">
                                        <a href="{{ route('caches.show', $cache) }}" wire:navigate class="text-sm font-semibold text-brand-ink hover:text-brand-forest">
                                            {{ $cache->name }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-brand-moss">
                                        {{ $cache->isShared() ? __('Shared · free') : __('Dedicated') }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-2">
                                            <div class="h-1.5 w-24 overflow-hidden rounded-full bg-brand-ink/10">
                                                <div class="h-full rounded-full {{ $meterTone($fraction) }}" style="width: {{ max(2, (int) round($fraction * 100)) }}%"></div>
                                            </div>
                                            <span class="text-xs text-brand-moss">{{ $bytes($u->residentBytes) }} / {{ $bytes($quota) }}</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-sm tabular-nums text-brand-moss">{{ number_format($u->itemCount) }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $statusTone[$cache->status] ?? 'bg-brand-sand/55 text-brand-moss ring-brand-ink/10' }}">
                                            {{ ucfirst($cache->status) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        @can('delete', $cache)
                                            <button type="button" wire:click="confirmDelete('{{ $cache->id }}')" class="text-sm font-medium text-brand-moss hover:text-brand-rust">
                                                {{ __('Delete') }}
                                            </button>
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($atLimit)
                    <p class="mt-3 text-sm text-brand-moss">
                        {{ __('Your plan (:plan) allows :limit cache(s).', ['plan' => $entitlement->planKey, 'limit' => $entitlement->limitLabel() ?? __('unlimited')]) }}
                    </p>
                @endif
            @endif
        </x-profile-shell>
    </div>

    {{-- Create --}}
    @if ($creating)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-brand-ink/40 p-4" wire:key="create-modal">
            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
                <h3 class="text-base font-semibold text-brand-ink">{{ __('New cache') }}</h3>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Free, bounded by storage rather than by requests.') }}</p>

                <label for="createName" class="mt-4 block text-sm font-medium text-brand-ink">{{ __('Name') }}</label>
                <input
                    id="createName"
                    type="text"
                    wire:model="createName"
                    placeholder="primary"
                    class="mt-1 w-full rounded-xl border-brand-ink/15 text-sm focus:border-brand-sage focus:ring-brand-sage"
                />
                @error('createName') <p class="mt-1 text-xs text-brand-rust">{{ $message }}</p> @enderror

                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" wire:click="cancelCreate" class="rounded-xl px-4 py-2 text-sm font-medium text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</button>
                    <button type="button" wire:click="create" class="rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest">{{ __('Create') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Delete --}}
    @if ($deletingId !== null)
        @php $deleting = $caches->firstWhere('id', $deletingId); @endphp
        @if ($deleting)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-brand-ink/40 p-4" wire:key="delete-modal">
                <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Delete :name', ['name' => $deleting->name]) }}</h3>
                    <p class="mt-2 text-sm text-brand-moss">
                        {{ __('Every key goes, including any lock an app is currently holding — a running job relying on WithoutOverlapping will lose its mutex. Credentials are revoked.') }}
                    </p>

                    <label for="deleteConfirmation" class="mt-4 block text-sm font-medium text-brand-ink">
                        {{ __('Type :name to confirm', ['name' => $deleting->name]) }}
                    </label>
                    <input
                        id="deleteConfirmation"
                        type="text"
                        wire:model="deleteConfirmation"
                        class="mt-1 w-full rounded-xl border-brand-ink/15 text-sm focus:border-brand-rust focus:ring-brand-rust"
                    />

                    <div class="mt-6 flex justify-end gap-2">
                        <button type="button" wire:click="cancelDelete" class="rounded-xl px-4 py-2 text-sm font-medium text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</button>
                        <button type="button" wire:click="destroy" class="rounded-xl bg-brand-rust px-4 py-2 text-sm font-semibold text-white hover:opacity-90">{{ __('Delete cache') }}</button>
                    </div>
                </div>
            </div>
        @endif
    @endif
</div>

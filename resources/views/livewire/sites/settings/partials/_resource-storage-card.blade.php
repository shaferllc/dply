{{--
    Object-storage resource card (multi-instance).

    Unlike every other binding type (one row per site), `storage` can hold several
    buckets, each mapped to its own Laravel filesystem disk. This card lists them
    all, lets you edit/detach each, shows the `config/filesystems.php` snippet for
    non-primary disks, and always offers "Add bucket".

    Inherits from resource-map.blade.php scope: $t, $statusDot, $statusBadge,
    $sectionUrl, $site.
--}}
@php
    $buckets = $t['bindings'] ?? collect();
    $hasBuckets = $buckets->isNotEmpty();
@endphp
<div
    wire:key="res-storage"
    data-resource-node="storage"
    @class([
        'group/node relative w-full rounded-xl border bg-white p-3 shadow-sm transition',
        'border-brand-forest/30 ring-1 ring-brand-forest/10' => $hasBuckets,
        'border-brand-ink/10 border-dashed hover:border-brand-forest/40 hover:shadow-md' => ! $hasBuckets,
    ])
>
    <div class="flex items-start gap-2.5">
        <span class="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $hasBuckets ? 'bg-brand-forest/10 text-brand-forest' : 'bg-brand-sand/50 text-brand-moss' }}">
            <x-dynamic-component :component="$t['icon']" class="h-5 w-5" />
        </span>
        <div class="min-w-0 flex-1">
            <h3 class="truncate text-sm font-semibold text-brand-ink">{{ $t['label'] }}</h3>
            @if ($hasBuckets)
                <p class="mt-0.5 text-[11px] leading-snug text-brand-moss">{{ trans_choice('{1} :count bucket attached|[2,*] :count buckets attached', $buckets->count(), ['count' => $buckets->count()]) }}</p>
            @else
                <p class="mt-0.5 line-clamp-2 text-[11px] leading-snug text-brand-moss">{{ $t['purpose'] }}</p>
            @endif
        </div>
    </div>

    @if ($hasBuckets)
        <ul class="mt-2.5 space-y-2">
            @foreach ($buckets as $bucket)
                @php
                    $disk = $this->storageDiskLabel($bucket);
                    $bucketName = $this->storageBucketLabel($bucket);
                    $isPrimary = $disk === 's3';
                    $snippet = $this->storageDiskSnippet($bucket);
                    $bEnvKeys = is_array($bucket->injected_env) ? array_keys($bucket->injected_env) : [];
                    $statusHint = match (true) {
                        filled($bucket->last_error ?? null) => (string) $bucket->last_error,
                        $bucket->status === 'configured' => __('Configured and ready.'),
                        $bucket->status === 'pending' => __('Attached, but not fully configured yet.'),
                        default => \Illuminate\Support\Str::headline((string) $bucket->status),
                    };
                @endphp
                <li wire:key="storage-{{ $bucket->id }}" x-data="{ open: false }" class="rounded-lg border border-brand-ink/10 bg-brand-cream/30 p-2">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="inline-flex items-center gap-1 rounded bg-white px-1.5 py-0.5 font-mono text-[10px] font-semibold text-brand-ink ring-1 ring-inset ring-brand-ink/10">
                                    {{ $disk }}@if ($isPrimary) <span class="text-brand-mist">{{ __('default') }}</span>@endif
                                </span>
                                <span title="{{ $statusHint }}" class="h-2 w-2 rounded-full {{ $statusDot[$bucket->status] ?? 'bg-brand-moss' }}"></span>
                            </div>
                            <p class="mt-1 truncate font-mono text-[11px] text-brand-moss">{{ $bucketName }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-0.5">
                            @if ($bEnvKeys !== [])
                                <button type="button" @click="open = ! open" title="{{ __('Details') }}" class="rounded-md p-1 text-brand-mist hover:bg-brand-sand/50 hover:text-brand-ink">
                                    <svg class="h-4 w-4 transition-transform duration-200" :class="open && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                                </button>
                            @endif
                            <button type="button" wire:click="openBindingModal('storage', 'attach', @js((string) $bucket->id))" title="{{ __('Edit') }}" class="rounded-md p-1 text-brand-mist hover:bg-brand-sand/50 hover:text-brand-ink">
                                <x-heroicon-o-pencil-square class="h-4 w-4" />
                            </button>
                            <button type="button"
                                wire:click="openConfirmActionModal('detachBinding', @js([(string) $bucket->id]), @js(__('Detach :disk?', ['disk' => $disk])), @js(__('Remove this bucket binding? Its injected variables will no longer be applied at deploy.')), @js(__('Detach')), true)"
                                title="{{ __('Detach') }}" class="rounded-md p-1 text-brand-mist hover:bg-rose-50 hover:text-rose-600">
                                <x-heroicon-o-x-mark class="h-4 w-4" />
                            </button>
                        </div>
                    </div>

                    <div x-show="open" x-cloak class="mt-2">
                        @if ($bEnvKeys !== [])
                            <p class="mb-1 text-[9px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Injected variables') }} ({{ count($bEnvKeys) }})</p>
                            <div class="flex flex-wrap gap-1">
                                @foreach ($bucket->injected_env as $k => $v)
                                    <div x-data="{ pop: false, show: false, copied: false, async copyVal() { try { await navigator.clipboard.writeText(@js((string) $v)); this.copied = true; setTimeout(() => this.copied = false, 1200); } catch (e) {} } }" class="relative">
                                        <button type="button" @click="pop = ! pop" class="rounded bg-white px-1.5 py-0.5 font-mono text-[10px] text-brand-moss shadow-sm hover:bg-brand-sand/40">{{ $k }}</button>
                                        <div x-show="pop" x-cloak x-transition.opacity class="absolute left-0 top-full z-30 mt-1 w-64 rounded-lg border border-brand-ink/10 bg-white p-2 text-left shadow-xl">
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="truncate font-mono text-[10px] font-semibold text-brand-ink">{{ $k }}</span>
                                                <div class="flex shrink-0 items-center gap-2 text-[10px] font-semibold">
                                                    <button type="button" @click="show = ! show" class="text-brand-sage hover:underline"><span x-show="! show">{{ __('Show') }}</span><span x-show="show" x-cloak>{{ __('Hide') }}</span></button>
                                                    <button type="button" @click="copyVal()" class="text-brand-sage hover:underline"><span x-show="! copied">{{ __('Copy') }}</span><span x-show="copied" x-cloak class="text-emerald-600">{{ __('Copied') }}</span></button>
                                                </div>
                                            </div>
                                            <p class="mt-1 break-all rounded bg-brand-cream/50 px-2 py-1 font-mono text-[10px] text-brand-ink">
                                                <span x-show="show" x-cloak>{{ ((string) $v) === '' ? '(empty)' : $v }}</span>
                                                <span x-show="! show">••••••••••</span>
                                            </p>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if ($snippet !== '')
                            {{-- Non-primary disks need a matching disk entry in the app's
                                 config/filesystems.php — hand the operator the exact array. --}}
                            <div x-data="{ copied: false, async copy() { try { await navigator.clipboard.writeText(@js($snippet)); this.copied = true; setTimeout(() => this.copied = false, 1200); } catch (e) {} } }" class="mt-2">
                                <div class="flex items-center justify-between">
                                    <p class="text-[9px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Add to config/filesystems.php → disks') }}</p>
                                    <button type="button" @click="copy()" class="text-[10px] font-semibold text-brand-sage hover:underline"><span x-show="! copied">{{ __('Copy') }}</span><span x-show="copied" x-cloak class="text-emerald-600">{{ __('Copied') }}</span></button>
                                </div>
                                <pre class="mt-1 overflow-x-auto rounded bg-brand-ink/90 p-2 font-mono text-[10px] leading-relaxed text-brand-cream">{{ $snippet }}</pre>
                            </div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    {{-- Actions --}}
    <div class="mt-2.5 flex flex-wrap items-center gap-1.5 border-t border-brand-ink/10 pt-2.5">
        <button type="button" wire:click="openBindingModal('storage', 'attach')" class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
            <x-heroicon-o-link class="h-3.5 w-3.5" /> {{ $hasBuckets ? __('Add bucket') : __('Attach') }}
        </button>
        <button type="button" wire:click="openBindingModal('storage', 'provision')" class="inline-flex items-center gap-1 rounded-md bg-brand-forest px-2 py-1 text-[11px] font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90">
            <x-heroicon-o-plus class="h-3.5 w-3.5" /> {{ __('Provision') }}
        </button>
    </div>
</div>

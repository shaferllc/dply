{{-- Resources tab — the site's resource/binding management, moved off the
     Environment tab. A clean vertical stack of per-resource cards (title +
     description + add/configure actions + attached rows), grouped by kind.
     Clicking an action opens the shared site-binding-modal (or the Logs editor
     for logging). VM sites only. --}}
@php
    use App\Support\Sites\SiteBindingCatalog;
    $hubBindings = $site->bindings; // HasMany collection
    $hubGroups = SiteBindingCatalog::grouped('vm', $hubBindings);
    $provisionTypes = ['database', 'redis', 'storage'];
    $configTypes = ['cache', 'queue', 'session', 'mail', 'broadcasting'];
    $statusBadge = [
        'configured' => 'bg-emerald-100 text-emerald-800',
        'pending' => 'bg-amber-100 text-amber-900',
        'provisioning' => 'bg-sky-100 text-sky-800',
        'error' => 'bg-rose-100 text-rose-800',
    ];
    $sectionUrl = fn (string $s) => route('sites.show', ['server' => $server, 'site' => $site, 'section' => $s]);
@endphp

<div class="space-y-8">
    @foreach ($hubGroups as $group)
        <div>
            <p class="mb-3 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ $group['label'] }}</p>
            <div class="space-y-3">
                @foreach ($group['types'] as $t)
                    @php
                        $type = $t['type'];
                        $binding = $t['binding'];
                        $attached = $t['attached'];
                        $envKeys = $attached && is_array($binding->injected_env) ? array_keys($binding->injected_env) : [];
                        $canProvision = in_array($type, $provisionTypes, true);
                        $canConfig = in_array($type, $configTypes, true);
                        $isLogging = $type === 'logging';
                        $needsRedis = in_array('redis', $t['needs'] ?? [], true)
                            && ! $hubBindings->contains(fn ($b) => $b->type === 'redis');
                    @endphp
                    <section wire:key="res-{{ $type }}" class="dply-card overflow-hidden">
                        <div class="flex flex-wrap items-start justify-between gap-3 px-5 py-4 sm:px-6">
                            <div class="flex items-start gap-3">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $attached ? 'bg-brand-forest/10 text-brand-forest' : 'bg-brand-sand/50 text-brand-moss' }}">
                                    <x-dynamic-component :component="$t['icon']" class="h-5 w-5" />
                                </span>
                                <div class="min-w-0">
                                    <h3 class="text-sm font-semibold uppercase tracking-wide text-brand-ink">{{ $t['label'] }}</h3>
                                    <p class="mt-0.5 text-sm leading-relaxed text-brand-moss">{{ $t['purpose'] }}</p>
                                    @if ($needsRedis)
                                        <p class="mt-1 inline-flex w-fit items-center gap-1 rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700">
                                            <x-heroicon-o-exclamation-triangle class="h-3 w-3" /> {{ __('Attach Redis first to use the redis driver') }}
                                        </p>
                                    @endif
                                </div>
                            </div>

                            {{-- actions --}}
                            <div class="flex shrink-0 items-center gap-2">
                                @if ($isLogging)
                                    <a href="{{ $sectionUrl('logs') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                        <x-heroicon-o-cog-6-tooth class="h-4 w-4" /> {{ $attached ? __('Edit') : __('Configure') }}
                                    </a>
                                @elseif ($canProvision)
                                    <button type="button" wire:click="openBindingModal('{{ $type }}', 'attach')" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                        <x-heroicon-o-link class="h-4 w-4" /> {{ __('Attach') }}
                                    </button>
                                    <button type="button" wire:click="openBindingModal('{{ $type }}', 'provision')" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90">
                                        <x-heroicon-o-plus class="h-4 w-4" /> {{ __('Provision') }}
                                    </button>
                                @elseif ($canConfig)
                                    <button type="button" wire:click="openBindingModal('{{ $type }}', 'attach')" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                        <x-heroicon-o-cog-6-tooth class="h-4 w-4" /> {{ $attached ? __('Edit') : __('Configure') }}
                                    </button>
                                @endif
                            </div>
                        </div>

                        {{-- attached row(s) --}}
                        @if ($attached)
                            <div class="flex flex-wrap items-center gap-3 border-t border-brand-ink/10 bg-brand-cream/30 px-5 py-3 sm:px-6">
                                <span class="font-mono text-sm font-semibold text-brand-ink">{{ $binding->name ?: $type }}</span>
                                <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $statusBadge[$binding->status] ?? 'bg-brand-sand/60 text-brand-moss' }}">{{ $binding->status }}</span>
                                @if ($envKeys !== [])
                                    <span class="truncate font-mono text-[10px] text-brand-mist" title="{{ implode(', ', $envKeys) }}">{{ collect($envKeys)->take(5)->implode(' · ') }}{{ count($envKeys) > 5 ? ' …' : '' }}</span>
                                @endif
                                <button type="button"
                                    wire:click="openConfirmActionModal('detachBinding', @js([(string) $binding->id]), @js(__('Detach :label?', ['label' => $t['label']])), @js(__('Remove this resource binding? Its injected variables will no longer be applied at deploy.')), @js(__('Detach')), true)"
                                    class="ml-auto inline-flex items-center gap-1 text-[11px] font-semibold text-brand-mist hover:text-rose-600">
                                    <x-heroicon-o-x-mark class="h-4 w-4" /> {{ __('Detach') }}
                                </button>
                            </div>
                        @else
                            <div class="border-t border-brand-ink/10 px-5 py-2.5 text-xs italic text-brand-mist sm:px-6">{{ __('Not configured') }}</div>
                        @endif
                    </section>
                @endforeach
            </div>
        </div>
    @endforeach

    {{-- Shared site-binding-modal (modal-only — we render our own cards above). --}}
    @include('livewire.sites.settings.partials.environment.resources', ['bindingModalOnly' => true])
</div>

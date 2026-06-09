@php
    // Lightweight summary stats — pulled inline so this redesign doesn't
    // require touching the Livewire component or its renderer signature.
    $connectedProviders = $credentials->pluck('provider')->unique();
    $verifiedCredentials = $credentials->filter(fn ($c) => ! empty($c->verified_at ?? null));

    $capabilityTabs = [
        ['id' => 'all', 'label' => __('All'), 'icon' => 'heroicon-o-squares-2x2'],
        ['id' => 'server', 'label' => __('Compute'), 'icon' => 'heroicon-o-cpu-chip'],
        ['id' => 'dns', 'label' => __('DNS'), 'icon' => 'heroicon-o-globe-alt'],
        ['id' => 'cdn', 'label' => __('CDN'), 'icon' => 'heroicon-o-bolt'],
    ];

    // Capability dots for the provider cards — the existing panel.blade.php
    // uses the same vocabulary, so cards and panel speak the same language.
    $capabilityDot = function (string $cap): array {
        return match ($cap) {
            'compute' => ['label' => __('Compute'), 'dot' => 'bg-brand-moss'],
            'dns' => ['label' => __('DNS'), 'dot' => 'bg-brand-sage'],
            'cdn' => ['label' => __('CDN'), 'dot' => 'bg-sky-500'],
            'app_platform' => ['label' => __('App Platform'), 'dot' => 'bg-violet-500'],
            'import' => ['label' => __('Import'), 'dot' => 'bg-amber-500'],
            default => ['label' => ucfirst(str_replace('_', ' ', $cap)), 'dot' => 'bg-brand-mist'],
        };
    };
@endphp

<x-livewire-validation-errors />

@if ($organization)
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
        ['label' => __('Provider credentials'), 'icon' => 'server'],
    ]" />
@else
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Settings'), 'href' => route('settings.profile'), 'icon' => 'cog-6-tooth'],
        ['label' => __('Provider credentials'), 'icon' => 'server'],
    ]" />
@endif

<div class="space-y-6">
    {{-- Hero: positioning + at-a-glance counts. The right-hand stat strip
         replaces the previous "stored encrypted" pill scattered inside the
         panel, so the reassurance is visible the moment the page opens. --}}
    <section class="dply-card overflow-hidden">
        <div class="grid gap-6 p-6 sm:p-8 lg:grid-cols-12 lg:items-center lg:gap-8">
            <div class="lg:col-span-7">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Credentials') }}</p>
                <h2 class="mt-2 text-xl font-semibold tracking-tight text-brand-ink">{{ __('Providers') }}</h2>
                <p class="mt-2 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Store API tokens for the clouds, registrars, and CDNs your organization uses. Tokens are encrypted at rest and validated against the provider when we can.') }}
                </p>
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <x-docs-link doc-route="docs.connect-provider">
                        <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                        {{ __('Provider setup guide') }}
                    </x-docs-link>
                    <a href="{{ route('docs.markdown', ['slug' => 'org-roles-and-limits']) }}" wire:navigate class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-sage underline decoration-brand-sage/35 underline-offset-2 transition hover:text-brand-ink hover:decoration-brand-ink/30">
                        {{ __('Roles & limits') }}
                        <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4 shrink-0 opacity-80" aria-hidden="true" />
                    </a>
                </div>
            </div>
            <dl class="grid grid-cols-3 gap-2 lg:col-span-5">
                <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Providers') }}</dt>
                    <dd class="mt-1 flex items-baseline gap-1.5">
                        <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $connectedProviders->count() }}</span>
                        <span class="text-[11px] text-brand-moss">{{ __('connected') }}</span>
                    </dd>
                </div>
                <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Credentials') }}</dt>
                    <dd class="mt-1 flex items-baseline gap-1.5">
                        <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $credentials->count() }}</span>
                        <span class="text-[11px] text-brand-moss">{{ trans_choice('saved|saved', $credentials->count()) }}</span>
                    </dd>
                </div>
                <div class="rounded-2xl border border-brand-sage/30 bg-brand-sage/8 px-4 py-3 shadow-sm">
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-forest/80">{{ __('Storage') }}</dt>
                    <dd class="mt-1 flex items-center gap-1.5">
                        <x-heroicon-o-lock-closed class="h-4 w-4 shrink-0 text-brand-forest" aria-hidden="true" />
                        <span class="text-[11px] font-medium text-brand-forest">{{ __('Encrypted at rest') }}</span>
                    </dd>
                </div>
            </dl>
        </div>
    </section>

    {{-- Capability filter. Replaces the legacy mobile-pills + desktop-tablist
         duplication with one responsive segmented control that works at every
         breakpoint. Wires to the same $tab property. --}}
    <section aria-label="{{ __('Capability filter') }}">
        <div role="tablist" class="inline-flex flex-wrap items-center gap-1 rounded-xl border border-brand-ink/10 bg-white p-1 shadow-sm">
            @foreach ($capabilityTabs as $tabItem)
                <button
                    type="button"
                    role="tab"
                    aria-selected="{{ $tab === $tabItem['id'] ? 'true' : 'false' }}"
                    wire:click="$set('tab', '{{ $tabItem['id'] }}')"
                    @class([
                        'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition',
                        'bg-brand-ink text-brand-cream shadow-sm' => $tab === $tabItem['id'],
                        'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => $tab !== $tabItem['id'],
                    ])
                >
                    <x-dynamic-component :component="$tabItem['icon']" class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ $tabItem['label'] }}
                </button>
            @endforeach
        </div>
    </section>

    {{-- First-run nudge. When the org has zero credentials anywhere,
         surface a single "Connect your first provider" card above the grid
         so an empty workspace doesn't read as a wall of greyed-out tiles. --}}
    @if ($credentials->isEmpty())
        <section class="rounded-2xl border border-brand-sage/30 bg-brand-sage/5 p-5 sm:p-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:gap-6">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/30">
                    <x-heroicon-o-link class="h-6 w-6" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Connect your first provider') }}</p>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Pick a provider below to link an API token. Tokens are encrypted before they hit disk and verified when possible.') }}</p>
                </div>
                <button
                    type="button"
                    x-on:click="$dispatch('open-add-provider-credential-modal')"
                    class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition hover:bg-brand-forest"
                >
                    <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Connect a provider') }}
                </button>
            </div>
        </section>
    @endif

    {{-- Provider grid. Replaces the dense sidebar with tappable cards laid
         out by category. Each card shows capability dots, a count badge,
         and a clear connect / manage state — clicking sets the active
         provider and the panel below jumps in to take over. --}}
    <section aria-label="{{ __('Pick a provider') }}" class="space-y-6">
        @foreach ($providerNav as $group)
            @php
                $groupItems = $group['items'];
                $groupTotal = count($groupItems);
                $groupAvailable = collect($groupItems)->reject(fn ($i) => ! empty($i['comingSoon']))->count();
                $groupConnected = collect($groupItems)->filter(fn ($i) => empty($i['comingSoon']) && $this->credentialCountFor($i['id']) > 0)->count();
                $groupIcon = match (strtolower((string) $group['label'])) {
                    default => 'heroicon-o-cube',
                };
                if (str_contains(strtolower((string) $group['label']), 'vps') || str_contains(strtolower((string) $group['label']), 'cloud')) {
                    $groupIcon = 'heroicon-o-cloud';
                } elseif (str_contains(strtolower((string) $group['label']), 'dns')) {
                    $groupIcon = 'heroicon-o-globe-alt';
                } elseif (str_contains(strtolower((string) $group['label']), 'cdn') || str_contains(strtolower((string) $group['label']), 'edge')) {
                    $groupIcon = 'heroicon-o-bolt';
                } elseif (str_contains(strtolower((string) $group['label']), 'registr') || str_contains(strtolower((string) $group['label']), 'image')) {
                    $groupIcon = 'heroicon-o-archive-box';
                } elseif (str_contains(strtolower((string) $group['label']), 'import')) {
                    $groupIcon = 'heroicon-o-arrow-down-tray';
                }
            @endphp
            <div>
                {{-- Group header. Tightened with an icon + connected/total
                     chip — gives quick read on how much of each family is
                     already wired up without scanning every card. --}}
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="inline-flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">
                        <x-dynamic-component :component="$groupIcon" class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                        {{ $group['label'] }}
                    </h3>
                    <span class="text-[10px] font-semibold tabular-nums text-brand-mist">
                        @if ($groupConnected > 0)
                            <span class="text-brand-forest">{{ $groupConnected }}</span><span class="text-brand-mist"> / {{ $groupAvailable }} {{ __('connected') }}</span>
                        @else
                            {{ $groupAvailable }} {{ __('available') }}
                        @endif
                    </span>
                </div>
                <ul class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach ($groupItems as $item)
                        @php
                            $count = $this->credentialCountFor($item['id']);
                            $isComing = ! empty($item['comingSoon']);
                            $isActive = $active_provider === $item['id'];
                            $firstCred = $credentials->firstWhere('provider', $item['id']);
                            $sampleCaps = $firstCred?->capabilities() ?? [];
                        @endphp
                        <li>
                            <button
                                type="button"
                                @if (! $isComing)
                                    x-on:click="$dispatch('open-add-provider-credential-modal', { provider: @js($item['id']) })"
                                @endif
                                @disabled($isComing)
                                @class([
                                    'group relative flex w-full flex-col items-start gap-3 rounded-2xl border bg-white p-4 text-left shadow-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-sage/40',
                                    'border-brand-ink/10 hover:-translate-y-0.5 hover:border-brand-sage/35 hover:shadow-md' => ! $isComing && $count === 0,
                                    'border-brand-sage/35 hover:-translate-y-0.5 hover:border-brand-sage/55 hover:shadow-md' => ! $isComing && $count > 0,
                                    'border-brand-ink/10 cursor-not-allowed opacity-65' => $isComing,
                                ])
                            >
                                <div class="flex w-full items-start justify-between gap-2">
                                    <span @class([
                                        'inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 ring-brand-ink/10',
                                        'bg-brand-sage/12 text-brand-forest' => $count > 0 && ! $isComing,
                                        'bg-brand-sand/45 text-brand-forest group-hover:bg-brand-sand/70' => $count === 0 || $isComing,
                                    ])>
                                        <x-credentials-provider-icon :provider="$item['id']" class="h-5 w-5" />
                                    </span>
                                    @if ($isComing)
                                        <span class="rounded-full bg-brand-sand/60 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-mist ring-1 ring-brand-ink/10">{{ __('Soon') }}</span>
                                    @elseif ($count > 0)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-brand-sage/15 px-2 py-0.5 text-[10px] font-semibold tabular-nums text-brand-forest ring-1 ring-brand-sage/20">
                                            <x-heroicon-m-check-circle class="h-3 w-3" aria-hidden="true" />
                                            {{ $count }}
                                        </span>
                                    @endif
                                </div>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-brand-ink">{{ $item['label'] }}</p>
                                    <p class="mt-0.5 text-[11px] text-brand-moss">
                                        @if ($isComing)
                                            {{ __('Coming soon') }}
                                        @elseif ($count === 0)
                                            {{ __('Not connected') }}
                                        @else
                                            {{ trans_choice(':n credential|:n credentials', $count, ['n' => $count]) }}
                                            @if ($firstCred?->created_at)
                                                <span class="text-brand-mist"> · </span>
                                                <span title="{{ $firstCred->created_at->toDayDateTimeString() }}">{{ __('added :time', ['time' => $firstCred->created_at->diffForHumans()]) }}</span>
                                            @endif
                                        @endif
                                    </p>
                                </div>
                                @if (! empty($sampleCaps))
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        @foreach ($sampleCaps as $cap)
                                            @php $chip = $capabilityDot($cap); @endphp
                                            <span class="inline-flex items-center gap-1 rounded-full bg-brand-cream/70 px-1.5 py-0.5 text-[10px] font-medium text-brand-moss ring-1 ring-brand-ink/10">
                                                <span class="inline-block h-1.5 w-1.5 shrink-0 rounded-full {{ $chip['dot'] }}" aria-hidden="true"></span>
                                                {{ $chip['label'] }}
                                            </span>
                                        @endforeach
                                    </div>
                                @endif

                                {{-- Trailing action hint. Visually a button-shaped
                                     chip, semantically a span — the whole card IS the
                                     trigger so we don't nest <button>s. --}}
                                @unless ($isComing)
                                    <span class="mt-auto inline-flex w-full items-center justify-between gap-2 rounded-lg border border-brand-ink/10 bg-brand-cream/40 px-2.5 py-1.5 text-[11px] font-semibold text-brand-ink transition group-hover:border-brand-sage/35 group-hover:bg-brand-sage/8 group-hover:text-brand-forest">
                                        <span class="inline-flex items-center gap-1.5">
                                            @if ($count > 0)
                                                <x-heroicon-o-cog-6-tooth class="h-4 w-4 shrink-0" aria-hidden="true" />
                                                {{ __('Manage') }}
                                            @else
                                                <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                                {{ __('Add new') }}
                                            @endif
                                        </span>
                                        <span aria-hidden="true" class="opacity-70 group-hover:opacity-100">→</span>
                                    </span>
                                @endunless
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </section>

    {{-- Provider management lives in a modal now (opened per-card). Keeps
         the index focused on connection state at a glance. --}}
</div>

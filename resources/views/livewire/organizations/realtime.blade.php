@php
    $statusTone = [
        \App\Models\RealtimeApp::STATUS_ACTIVE => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        \App\Models\RealtimeApp::STATUS_PROVISIONING => 'bg-amber-100 text-amber-700 ring-amber-200',
        \App\Models\RealtimeApp::STATUS_PAUSED => 'bg-brand-sand/55 text-brand-moss ring-brand-ink/10',
        \App\Models\RealtimeApp::STATUS_FAILED => 'bg-red-100 text-red-700 ring-red-200',
    ];
    $money = fn (int $cents): string => '$'.number_format($cents / 100, 2);
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell :organization="$organization" section="realtime" :breadcrumb="$breadcrumbs">
            <x-livewire-validation-errors />

            {{-- Hero + billing notice: what realtime is and what it costs this workspace. --}}
            <section class="dply-card overflow-hidden">
                <div class="grid gap-6 p-6 sm:p-8 lg:grid-cols-12 lg:items-center lg:gap-8">
                    <div class="lg:col-span-7">
                        <div class="flex items-start gap-3">
                            <x-icon-badge size="md">
                                <x-heroicon-o-signal class="h-6 w-6" aria-hidden="true" />
                            </x-icon-badge>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Broadcasting') }}</p>
                                <h2 class="mt-1 text-xl font-semibold tracking-tight text-brand-ink">{{ __('Realtime') }}</h2>
                                <p class="mt-2 max-w-xl text-sm leading-relaxed text-brand-moss">
                                    {{ __('Managed Pusher-compatible relay for your apps. Each active app is billed monthly by its connection tier and added to this workspace’s subscription.') }}
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="lg:col-span-5">
                        {{-- Billing notice: live total added to the bill. --}}
                        <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/30 p-5">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('On this workspace’s bill') }}</p>
                            <p class="mt-1 text-2xl font-semibold text-brand-ink">{{ $money($monthlyCents) }}<span class="text-sm font-normal text-brand-moss">/{{ __('mo') }}</span></p>
                            <p class="mt-1 text-xs text-brand-moss">
                                {{ trans_choice('{0}No active apps yet.|{1}:count active app across your sites.|[2,*]:count active apps across your sites.', $activeCount, ['count' => $activeCount]) }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            @if ($apps->isEmpty())
                {{-- Empty state: apps are provisioned from a site's broadcasting binding. --}}
                <section class="dply-card mt-6 p-8 text-center">
                    <x-icon-badge size="md" class="mx-auto">
                        <x-heroicon-o-signal class="h-6 w-6" aria-hidden="true" />
                    </x-icon-badge>
                    <h3 class="mt-4 text-base font-semibold text-brand-ink">{{ __('No broadcasting apps yet') }}</h3>
                    <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed text-brand-moss">
                        {{ __('Add managed broadcasting to a site from its Resources tab → Configure broadcasting → dply realtime. Provisioned apps show up here to manage and bill.') }}
                    </p>
                    @unless ($featureActive)
                        <p class="mx-auto mt-3 max-w-md text-xs text-brand-moss">
                            {{ __('Managed realtime isn’t enabled for this workspace yet. Bring-your-own broadcasting is always available on a site.') }}
                        </p>
                    @endunless
                </section>
            @else
                <section class="mt-6 space-y-4">
                    @foreach ($apps as $app)
                        @php
                            $sites = $siteUsage->get($app->id) ?? collect();
                            $tier = $app->tierConfig();
                        @endphp
                        <article class="dply-card p-5 sm:p-6" @if ($app->status === \App\Models\RealtimeApp::STATUS_PROVISIONING) wire:poll.5s @endif>
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <h3 class="truncate text-base font-semibold text-brand-ink">{{ $app->name }}</h3>
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset {{ $statusTone[$app->status] ?? 'bg-brand-sand/55 text-brand-moss ring-brand-ink/10' }}">
                                            {{ ucfirst($app->status) }}
                                        </span>
                                    </div>
                                    <p class="mt-1 font-mono text-xs text-brand-moss">{{ $app->app_key }}</p>
                                    <p class="mt-0.5 font-mono text-[11px] text-brand-moss/80">{{ $app->host() }}</p>
                                    @if ($app->status === \App\Models\RealtimeApp::STATUS_FAILED && $app->error_message)
                                        <p class="mt-2 rounded-md bg-red-50 px-2 py-1 text-xs text-red-700">{{ $app->error_message }}</p>
                                    @endif
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-semibold text-brand-forest">{{ $tier['label'] }} · {{ $money($app->priceCents()) }}/{{ __('mo') }}</p>
                                    <p class="mt-0.5 text-xs text-brand-moss">
                                        {{ number_format((int) ($app->peak_connections ?? 0)) }} / {{ number_format($tier['max_connections']) }} {{ __('peak conns') }}
                                    </p>
                                </div>
                            </div>

                            {{-- Sites depending on this app. --}}
                            <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-brand-ink/10 pt-4">
                                @if ($sites->isNotEmpty())
                                    <span class="text-xs text-brand-moss">{{ __('Used by') }}</span>
                                    @foreach ($sites as $binding)
                                        @if ($binding->site)
                                            <span class="inline-flex items-center rounded-md bg-brand-sand/40 px-2 py-0.5 text-xs text-brand-ink ring-1 ring-inset ring-brand-ink/10">{{ $binding->site->name }}</span>
                                        @endif
                                    @endforeach
                                @else
                                    <span class="text-xs text-brand-moss">{{ __('Not attached to any site.') }}</span>
                                @endif

                                <div class="ml-auto flex items-center gap-2">
                                    <a href="{{ route('organizations.realtime.show', [$organization, $app]) }}" wire:navigate
                                        class="inline-flex items-center gap-1 text-xs font-semibold text-brand-forest hover:underline">
                                        {{ __('Manage') }} <x-heroicon-o-arrow-right class="h-3.5 w-3.5" />
                                    </a>
                                    @if ($canManage)
                                        <x-secondary-button type="button" wire:click="startTierChange('{{ $app->id }}')" class="text-xs">
                                            {{ __('Change tier') }}
                                        </x-secondary-button>
                                        <button type="button" wire:click="confirmDelete('{{ $app->id }}')" class="text-xs font-medium text-red-600 hover:text-red-700">
                                            {{ __('Delete') }}
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </article>
                    @endforeach
                </section>
            @endif
        </x-organization-shell>
    </div>

    {{-- Tier-change modal --}}
    <x-modal name="realtime-tier-modal" :show="false" maxWidth="lg" overlayClass="bg-brand-ink/30" focusable>
        @if ($managingApp)
            @php
                $currentCents = $managingApp->priceCents();
                $selectedCents = (int) ($tiers[$selectedTier]['price_cents'] ?? $currentCents);
                $isUpgrade = $selectedCents > $currentCents;
            @endphp
            <form wire:submit="changeTier">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <x-icon-badge>
                        <x-heroicon-o-signal class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Change connection tier') }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ $managingApp->name }}</h2>
                        <p class="mt-1 text-sm leading-6 text-brand-moss">{{ __('Currently :tier · :price/mo. Changing tiers adjusts the hard connection cap and this workspace’s bill.', ['tier' => $managingApp->tierConfig()['label'], 'price' => $money($currentCents)]) }}</p>
                    </div>
                </div>

                <div class="space-y-4 px-6 py-6">
                    <div class="grid gap-2 sm:grid-cols-3">
                        @foreach ($tiers as $slug => $tier)
                            <button type="button" wire:click="$set('selectedTier', '{{ $slug }}')" class="rounded-lg border p-3 text-left transition-colors {{ $selectedTier === $slug ? 'border-brand-forest bg-brand-forest/5 ring-1 ring-brand-forest/40' : 'border-brand-ink/10 hover:bg-brand-sand/30' }}">
                                <div class="text-sm font-semibold text-brand-ink">{{ $tier['label'] }}</div>
                                <div class="mt-0.5 text-[11px] text-brand-moss">{{ number_format($tier['max_connections']) }} {{ __('connections') }}</div>
                                <div class="mt-1 text-xs font-semibold text-brand-forest">{{ $money((int) $tier['price_cents']) }}/{{ __('mo') }}</div>
                            </button>
                        @endforeach
                    </div>

                    @if ($isUpgrade)
                        <label class="flex items-start gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2.5 text-xs text-brand-moss">
                            <input type="checkbox" wire:model="confirmTierCharge" class="mt-0.5 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                            <span>{{ __('I understand this raises this app’s charge from :from to :to per month on this workspace’s bill.', ['from' => $money($currentCents), 'to' => $money($selectedCents)]) }}</span>
                        </label>
                    @elseif ($selectedCents < $currentCents)
                        <p class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2.5 text-xs text-brand-moss">{{ __('Downgrading lowers this app’s charge to :to/mo. The lower connection cap takes effect immediately.', ['to' => $money($selectedCents)]) }}</p>
                    @endif
                </div>

                <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <x-secondary-button type="button" wire:click="cancelTierChange">{{ __('Cancel') }}</x-secondary-button>
                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="changeTier">
                        <span wire:loading.remove wire:target="changeTier">{{ __('Save tier') }}</span>
                        <span wire:loading wire:target="changeTier" class="inline-flex items-center gap-2"><x-spinner variant="cream" size="sm" />{{ __('Saving…') }}</span>
                    </x-primary-button>
                </div>
            </form>
        @endif
    </x-modal>

    {{-- Delete confirmation modal --}}
    <x-modal name="realtime-delete-modal" :show="false" maxWidth="md" overlayClass="bg-brand-ink/30" focusable>
        @if ($deletingApp)
            <div>
                <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-red-100 text-red-600 ring-1 ring-red-200">
                        <x-heroicon-o-trash class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-red-600">{{ __('Delete broadcasting app') }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ $deletingApp->name }}</h2>
                    </div>
                </div>
                <div class="space-y-3 px-6 py-6 text-sm leading-6 text-brand-moss">
                    <p>{{ __('This tears the app down on the relay and stops its :price/mo charge on this workspace’s bill. Connections are revoked immediately.', ['price' => $money($deletingApp->priceCents())]) }}</p>
                    @if ($deletingAppSites->isNotEmpty())
                        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-800">
                            {{ __('Warning: :count site(s) still broadcast through this app and will lose realtime until you point them elsewhere:', ['count' => $deletingAppSites->count()]) }}
                            <span class="font-medium">{{ $deletingAppSites->map(fn ($b) => $b->site?->name)->filter()->join(', ') }}</span>
                        </div>
                    @endif
                </div>
                <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <x-secondary-button type="button" wire:click="cancelDelete">{{ __('Cancel') }}</x-secondary-button>
                    <x-danger-button type="button" wire:click="deleteApp" wire:loading.attr="disabled" wire:target="deleteApp">
                        <span wire:loading.remove wire:target="deleteApp">{{ __('Delete app') }}</span>
                        <span wire:loading wire:target="deleteApp" class="inline-flex items-center gap-2"><x-spinner variant="cream" size="sm" />{{ __('Deleting…') }}</span>
                    </x-danger-button>
                </div>
            </div>
        @endif
    </x-modal>
</div>

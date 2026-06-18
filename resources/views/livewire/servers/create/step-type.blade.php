@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
    ];
@endphp

<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <x-server-create-stepper :current="1" :reached="1" :mode="$form->mode" :hostKind="$form->custom_host_kind" :providerHostKind="$form->provider_host_kind" />

    @if ($migrationSourcePloiServerId || $migrationSourceForgeServerId)
        @php
            $isForge = $migrationSourceKind === 'forge';
            $sourceLabel = $isForge ? 'Laravel Forge' : 'Ploi';
            $inventoryRoute = $isForge ? route('imports.forge.inventory') : route('imports.ploi.inventory');
        @endphp
        <section class="mt-6 dply-card overflow-hidden border-amber-200">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                <x-icon-badge tone="amber">
                    <x-heroicon-o-arrow-path-rounded-square class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Migrate from :source', ['source' => $sourceLabel]) }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Creating the dply server for :label', ['label' => $migrationSourceLabel]) }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Walk through the wizard to provision the destination server. Once it is ready, your selected sites migrate automatically — code, env, databases, crons, and SSL.') }}
                    </p>
                    <a href="{{ $inventoryRoute }}" wire:navigate class="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold text-amber-900 hover:text-amber-700">
                        <x-heroicon-m-arrow-left class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Cancel and return to inventory') }}
                    </a>
                </div>
            </div>
        </section>
    @endif

    <form wire:submit.prevent="next" class="mt-6">
        {{-- Hero: step + intent. --}}
        <x-hero-card
            icon="server-stack"
            iconSize="md"
            :eyebrow="__('Step :n of :total', ['n' => 1, 'total' => $totalSteps])"
            :title="__('Create a server')"
            :description="__('Pick how you want to add this server, then give it a memorable name. You can change either choice before the final review.')"
        >
            @if ($dockerHostHinted)
                <div class="flex items-start gap-2 rounded-xl border border-sky-200 bg-sky-50/70 px-4 py-3 text-sm leading-relaxed text-sky-900">
                    <x-heroicon-m-information-circle class="mt-0.5 h-4 w-4 shrink-0 text-sky-600" aria-hidden="true" />
                    <span>{{ __('Detected a Docker-host launch path. Provider mode is preselected with a Docker host; you can change the host kind on the next step.') }}</span>
                </div>
            @endif

            <x-slot:stats>
                <dl class="grid grid-cols-2 gap-2">
                    <div @class([
                        'rounded-2xl border px-4 py-3 shadow-sm',
                        'border-brand-sage/30 bg-brand-sage/8' => $form->mode === 'provider',
                        'border-brand-ink/10 bg-white' => $form->mode !== 'provider',
                    ])>
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Mode') }}</dt>
                        <dd class="mt-1 truncate text-sm font-semibold text-brand-ink">
                            {{ $form->mode === 'provider' ? __('Provider') : ($form->mode === 'custom' ? __('Custom (BYO)') : __('Not set')) }}
                        </dd>
                        <p class="mt-1 text-[11px] text-brand-mist">{{ __('How this server is added') }}</p>
                    </div>
                    <div @class([
                        'rounded-2xl border px-4 py-3 shadow-sm',
                        'border-brand-sage/30 bg-brand-sage/8' => filled($form->name),
                        'border-brand-ink/10 bg-white' => ! filled($form->name),
                    ])>
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Name') }}</dt>
                        <dd class="mt-1 truncate font-mono text-sm font-semibold text-brand-ink">{{ filled($form->name) ? $form->name : '—' }}</dd>
                        <p class="mt-1 text-[11px] text-brand-mist">{{ __('Friendly identifier') }}</p>
                    </div>
                </dl>
            </x-slot:stats>
        </x-hero-card>

        <div class="mt-6 space-y-6">
            {{-- Mode selection --}}
            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <x-icon-badge>
                        <x-heroicon-o-cube class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0 flex-1">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Mode') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('How are you adding this server?') }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Pick one — provider provisioning or bring your own host over SSH.') }}</p>
                    </div>
                    <span class="shrink-0 rounded-full bg-brand-sand/60 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">{{ __('Required') }}</span>
                </div>
                <div class="p-6 sm:p-7">
                    {{-- Server-driven: classes follow $form->mode so a fast A/B/A
                         click sequence can never desync the UI from server state.
                         Both buttons disable while either action is in flight,
                         which queues only one selection change at a time. --}}
                    <div class="grid gap-4 sm:grid-cols-2">
                        @php $providerSelected = $form->mode === 'provider'; @endphp
                        <button
                            type="button"
                            wire:key="mode-provider-{{ $providerSelected ? 'on' : 'off' }}"
                            wire:click="chooseProviderMode"
                            wire:loading.attr="disabled"
                            wire:target="chooseProviderMode,chooseCustomMode"
                            aria-pressed="{{ $providerSelected ? 'true' : 'false' }}"
                            @class([
                                'group relative flex flex-col rounded-2xl border-2 p-5 text-left shadow-sm transition-all disabled:cursor-wait disabled:opacity-70',
                                'border-brand-sage bg-brand-sage/5 ring-2 ring-brand-sage/30 ring-offset-2 ring-offset-white' => $providerSelected,
                                'border-brand-ink/10 bg-white hover:-translate-y-0.5 hover:border-brand-sage/30 hover:shadow-md' => ! $providerSelected,
                            ])
                        >
                            <div class="flex items-start justify-between gap-3">
                                <span @class([
                                    'flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 transition-colors',
                                    'bg-brand-sage text-white ring-brand-sage/30' => $providerSelected,
                                    'bg-brand-sage/15 text-brand-forest ring-brand-sage/25 group-hover:bg-brand-sage/20' => ! $providerSelected,
                                ])>
                                    <x-heroicon-o-cloud-arrow-up class="h-5 w-5" aria-hidden="true" />
                                </span>
                                <x-heroicon-s-check-circle @class([
                                    'h-6 w-6 shrink-0 transition-colors',
                                    'text-brand-sage' => $providerSelected,
                                    'text-brand-ink/15' => ! $providerSelected,
                                ]) aria-hidden="true" />
                            </div>
                            <span class="mt-4 block text-sm font-semibold text-brand-ink">{{ __('Provision with a provider') }}</span>
                            <span class="mt-1 block text-xs leading-relaxed text-brand-moss">{{ __('We talk to DigitalOcean, AWS, Hetzner, Vultr, Linode and friends, then bring up a fresh VM ready for your stack.') }}</span>
                            <div class="mt-3 flex flex-wrap gap-1.5">
                                <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('DigitalOcean') }}</span>
                                <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('AWS') }}</span>
                                <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Hetzner') }}</span>
                                <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Vultr') }}</span>
                                <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Linode') }}</span>
                                <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('+3 more') }}</span>
                            </div>
                        </button>

                        @php $customSelected = $form->mode === 'custom'; @endphp
                        <button
                            type="button"
                            wire:key="mode-custom-{{ $customSelected ? 'on' : 'off' }}"
                            wire:click="chooseCustomMode"
                            wire:loading.attr="disabled"
                            wire:target="chooseProviderMode,chooseCustomMode"
                            aria-pressed="{{ $customSelected ? 'true' : 'false' }}"
                            @class([
                                'group relative flex flex-col rounded-2xl border-2 p-5 text-left shadow-sm transition-all disabled:cursor-wait disabled:opacity-70',
                                'border-brand-sage bg-brand-sage/5 ring-2 ring-brand-sage/30 ring-offset-2 ring-offset-white' => $customSelected,
                                'border-brand-ink/10 bg-white hover:-translate-y-0.5 hover:border-brand-sage/30 hover:shadow-md' => ! $customSelected,
                            ])
                        >
                            <div class="flex items-start justify-between gap-3">
                                <span @class([
                                    'flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 transition-colors',
                                    'bg-brand-sage text-white ring-brand-sage/30' => $customSelected,
                                    'bg-brand-sand/55 text-brand-forest ring-brand-ink/10 group-hover:bg-brand-sage/15 group-hover:text-brand-forest group-hover:ring-brand-sage/20' => ! $customSelected,
                                ])>
                                    <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
                                </span>
                                <x-heroicon-s-check-circle @class([
                                    'h-6 w-6 shrink-0 transition-colors',
                                    'text-brand-sage' => $customSelected,
                                    'text-brand-ink/15' => ! $customSelected,
                                ]) aria-hidden="true" />
                            </div>
                            <span class="mt-4 block text-sm font-semibold text-brand-ink">{{ __('Custom server (BYO)') }}</span>
                            <span class="mt-1 block text-xs leading-relaxed text-brand-moss">{{ __('Bring your own machine: dply connects over SSH and treats it like any other host. No cloud APIs.') }}</span>
                            <div class="mt-3 flex flex-wrap gap-1.5">
                                <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('SSH key auth') }}</span>
                                <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Bare metal') }}</span>
                                <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Existing VPS') }}</span>
                            </div>
                        </button>
                    </div>
                    <x-input-error :messages="$errors->get('form.mode')" class="mt-3" />
                </div>
            </section>

            {{-- Server name --}}
            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <x-icon-badge>
                        <x-heroicon-o-tag class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0 flex-1">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Identity') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Server name') }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Letters, digits, dot, underscore, hyphen — up to 64 chars.') }}</p>
                    </div>
                </div>
                <div class="p-6 sm:p-7">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-stretch">
                        <div class="relative flex-1">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-brand-mist">
                                <x-heroicon-o-hashtag class="h-4 w-4" aria-hidden="true" />
                            </span>
                            <x-text-input id="form_name" wire:model.live="form.name" type="text" class="block w-full pl-9 font-mono text-base" required autocomplete="off" />
                        </div>
                        <button
                            type="button"
                            wire:click="regenerateName"
                            wire:loading.attr="disabled"
                            wire:target="regenerateName"
                            class="inline-flex shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="regenerateName" class="inline-flex items-center gap-2 whitespace-nowrap">
                                <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Regenerate') }}
                            </span>
                            <span wire:loading wire:target="regenerateName" class="inline-flex items-center gap-2 whitespace-nowrap">
                                <x-spinner variant="zinc" size="sm" />
                                {{ __('Regenerating…') }}
                            </span>
                        </button>
                    </div>
                    <x-input-error :messages="$errors->get('form.name')" class="mt-2" />
                </div>
            </section>
        </div>

        {{-- Footer actions: sandy band so the CTA visually sits on the family chrome. --}}
        <footer class="mt-6 flex flex-col-reverse items-stretch justify-between gap-3 rounded-2xl border border-brand-ink/10 bg-brand-sand/25 px-5 py-4 shadow-sm sm:flex-row sm:items-center">
            <button type="button" wire:click="openDiscardDraftModal" class="inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-lg border border-transparent px-3 py-2 text-sm font-medium text-brand-moss transition-colors hover:bg-white hover:text-red-700">
                <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                {{ __('Discard draft') }}
            </button>
            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="next"
                class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-wait disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="next">{{ __('Continue') }}</span>
                <x-heroicon-o-arrow-right wire:loading.remove wire:target="next" class="h-4 w-4 shrink-0" aria-hidden="true" />
                <span wire:loading wire:target="next" class="inline-flex items-center gap-2 whitespace-nowrap">
                    <x-spinner variant="cream" size="sm" />
                    {{ __('Saving…') }}
                </span>
            </button>
        </footer>
    </form>

    @include('livewire.servers.create._discard-draft-modal')
</div>

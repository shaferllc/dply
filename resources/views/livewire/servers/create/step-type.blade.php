<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 space-y-8">
    <x-server-create-stepper :current="1" :reached="1" :mode="$form->mode" :hostKind="$form->custom_host_kind" :providerHostKind="$form->provider_host_kind" />

    @if ($migrationSourcePloiServerId || $migrationSourceForgeServerId)
        @php
            $isForge = $migrationSourceKind === 'forge';
            $sourceLabel = $isForge ? 'Laravel Forge' : 'Ploi';
            $inventoryRoute = $isForge ? route('imports.forge.inventory') : route('imports.ploi.inventory');
        @endphp
        <section class="rounded-2xl border border-amber-200 bg-amber-50/70 p-6">
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-amber-800">{{ __('Migrate from :source', ['source' => $sourceLabel]) }}</p>
            <h2 class="mt-2 text-2xl font-semibold text-amber-950">{{ __('Creating the dply server for :label', ['label' => $migrationSourceLabel]) }}</h2>
            <p class="mt-3 max-w-3xl text-sm leading-6 text-amber-900">
                {{ __('Walk through the wizard to provision the destination server. Once it is ready, your selected sites migrate automatically — code, env, databases, crons, and SSL.') }}
            </p>
            <a href="{{ $inventoryRoute }}" wire:navigate class="mt-4 inline-flex items-center text-sm font-semibold text-amber-900 underline underline-offset-2 hover:text-amber-700">{{ __('Cancel and return to inventory') }}</a>
        </section>
    @endif

    <form wire:submit.prevent="next" class="space-y-10">
        <header class="relative overflow-hidden rounded-3xl border border-brand-ink/10 bg-gradient-to-br from-brand-cream via-white to-brand-sand/20 px-6 py-8 shadow-sm sm:px-10 sm:py-10">
            <div class="absolute -right-12 -top-12 h-44 w-44 rounded-full bg-brand-sage/10 blur-3xl" aria-hidden="true"></div>
            <div class="absolute -bottom-16 -left-12 h-40 w-40 rounded-full bg-brand-gold/10 blur-3xl" aria-hidden="true"></div>
            <div class="relative">
                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Step :n of :total', ['n' => 1, 'total' => $totalSteps]) }}</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-brand-ink sm:text-4xl">{{ __('Create a server') }}</h1>
                <p class="mt-2 max-w-prose text-sm leading-relaxed text-brand-moss sm:text-base">{{ __('Pick how you want to add this server, then give it a memorable name. You can change either choice before the final review.') }}</p>
                @if ($dockerHostHinted)
                    <div class="mt-5 inline-flex items-start gap-3 rounded-2xl border border-sky-200/80 bg-white/80 px-4 py-3 text-sm text-slate-800 shadow-sm">
                        <x-heroicon-m-information-circle class="mt-0.5 h-5 w-5 shrink-0 text-sky-600" />
                        <span>{{ __('Detected a Docker-host launch path. Provider mode is preselected with a Docker host; you can change the host kind on the next step.') }}</span>
                    </div>
                @endif
            </div>
        </header>

        <section class="space-y-4">
            <div class="flex items-baseline justify-between gap-3">
                <h2 class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('How are you adding this server?') }}</h2>
                <span class="text-xs font-medium text-brand-mist">{{ __('Pick one') }}</span>
            </div>
            {{-- Selection is driven by Alpine so the visual switch is
                 instant — `wire:click` had a perceptible flash while the
                 Livewire round-trip re-morphed the cards. We mirror
                 $form->mode → Alpine on mount, then push back to the
                 server via $wire.set so persistence still happens. --}}
            <div
                class="grid gap-4 sm:grid-cols-2"
                x-data="{ mode: @js($form->mode) }"
                x-init="$watch('$wire.form.mode', value => mode = value)"
            >
                <button
                    type="button"
                    @click="mode = 'provider'; $wire.chooseProviderMode()"
                    :aria-pressed="mode === 'provider' ? 'true' : 'false'"
                    :class="mode === 'provider'
                        ? 'border-brand-sage bg-gradient-to-br from-brand-sage/15 via-brand-sage/5 to-white shadow-brand-sage/15 ring-2 ring-brand-sage/30 ring-offset-2 ring-offset-brand-cream'
                        : 'border-brand-ink/10 bg-white hover:-translate-y-0.5 hover:border-brand-sage/40 hover:shadow-md'"
                    class="group relative flex flex-col rounded-2xl border-2 p-6 text-left shadow-sm transition-all"
                >
                    <div class="flex items-start justify-between gap-3">
                        <span
                            :class="mode === 'provider'
                                ? 'bg-brand-sage text-white shadow-md shadow-brand-sage/20'
                                : 'bg-brand-sand/40 text-brand-forest group-hover:bg-brand-sage/15'"
                            class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-xl transition-colors"
                        >
                            <x-heroicon-o-cloud-arrow-up class="h-6 w-6" />
                        </span>
                        <span
                            :class="mode === 'provider' ? 'border-brand-sage bg-brand-sage text-white' : 'border-brand-ink/20 bg-white'"
                            class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full border-2 transition-colors"
                        >
                            {{-- Wrapper span (not the SVG) holds x-show so toggle is reliable; x-show on the heroicon SVG had stale display on switch. --}}
                            <span x-show="mode === 'provider'" x-cloak class="inline-flex">
                                <x-heroicon-s-check class="h-4 w-4" />
                            </span>
                        </span>
                    </div>
                    <span class="mt-4 block text-base font-semibold text-brand-ink">{{ __('Provision with a provider') }}</span>
                    <span class="mt-1.5 block text-sm leading-relaxed text-brand-moss">{{ __('We talk to DigitalOcean, AWS, Hetzner, Vultr, Linode and friends, then bring up a fresh VM ready for your stack.') }}</span>
                    <div class="mt-4 flex flex-wrap gap-1.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss/80">
                        <span class="rounded-full bg-brand-ink/[0.04] px-2 py-0.5 ring-1 ring-brand-ink/[0.06]">{{ __('DigitalOcean') }}</span>
                        <span class="rounded-full bg-brand-ink/[0.04] px-2 py-0.5 ring-1 ring-brand-ink/[0.06]">{{ __('AWS') }}</span>
                        <span class="rounded-full bg-brand-ink/[0.04] px-2 py-0.5 ring-1 ring-brand-ink/[0.06]">{{ __('Hetzner') }}</span>
                        <span class="rounded-full bg-brand-ink/[0.04] px-2 py-0.5 ring-1 ring-brand-ink/[0.06]">{{ __('Vultr') }}</span>
                        <span class="rounded-full bg-brand-ink/[0.04] px-2 py-0.5 ring-1 ring-brand-ink/[0.06]">{{ __('Linode') }}</span>
                        <span class="rounded-full bg-brand-ink/[0.04] px-2 py-0.5 ring-1 ring-brand-ink/[0.06]">{{ __('+3 more') }}</span>
                    </div>
                </button>

                <button
                    type="button"
                    @click="mode = 'custom'; $wire.chooseCustomMode()"
                    :aria-pressed="mode === 'custom' ? 'true' : 'false'"
                    :class="mode === 'custom'
                        ? 'border-brand-sage bg-gradient-to-br from-brand-sage/15 via-brand-sage/5 to-white shadow-brand-sage/15 ring-2 ring-brand-sage/30 ring-offset-2 ring-offset-brand-cream'
                        : 'border-brand-ink/10 bg-white hover:-translate-y-0.5 hover:border-brand-sage/40 hover:shadow-md'"
                    class="group relative flex flex-col rounded-2xl border-2 p-6 text-left shadow-sm transition-all"
                >
                    <div class="flex items-start justify-between gap-3">
                        <span
                            :class="mode === 'custom'
                                ? 'bg-brand-sage text-white shadow-md shadow-brand-sage/20'
                                : 'bg-brand-sand/40 text-brand-forest group-hover:bg-brand-sage/15'"
                            class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-xl transition-colors"
                        >
                            <x-heroicon-o-server-stack class="h-6 w-6" />
                        </span>
                        <span
                            :class="mode === 'custom' ? 'border-brand-sage bg-brand-sage text-white' : 'border-brand-ink/20 bg-white'"
                            class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full border-2 transition-colors"
                        >
                            <span x-show="mode === 'custom'" x-cloak class="inline-flex">
                                <x-heroicon-s-check class="h-4 w-4" />
                            </span>
                        </span>
                    </div>
                    <span class="mt-4 block text-base font-semibold text-brand-ink">{{ __('Custom server (BYO)') }}</span>
                    <span class="mt-1.5 block text-sm leading-relaxed text-brand-moss">{{ __('Bring your own machine: dply connects over SSH and treats it like any other host. No cloud APIs.') }}</span>
                    <div class="mt-4 flex flex-wrap gap-1.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss/80">
                        <span class="rounded-full bg-brand-ink/[0.04] px-2 py-0.5 ring-1 ring-brand-ink/[0.06]">{{ __('SSH key auth') }}</span>
                        <span class="rounded-full bg-brand-ink/[0.04] px-2 py-0.5 ring-1 ring-brand-ink/[0.06]">{{ __('Bare metal') }}</span>
                        <span class="rounded-full bg-brand-ink/[0.04] px-2 py-0.5 ring-1 ring-brand-ink/[0.06]">{{ __('Existing VPS') }}</span>
                    </div>
                </button>
            </div>
            <x-input-error :messages="$errors->get('form.mode')" class="mt-1" />
        </section>

        <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-7">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <x-input-label for="form_name" :value="__('Server name')" class="!text-[11px] !font-semibold !uppercase !tracking-[0.22em] text-brand-sage" />
                <span class="text-xs text-brand-mist">{{ __('Letters, digits, dot, underscore, hyphen — up to 64 chars.') }}</span>
            </div>
            <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-stretch">
                <div class="relative flex-1">
                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-brand-mist">
                        <x-heroicon-o-tag class="h-4 w-4" />
                    </span>
                    <x-text-input id="form_name" wire:model="form.name" type="text" class="block w-full pl-10 font-mono text-base" required autocomplete="off" />
                </div>
                <button
                    type="button"
                    wire:click="regenerateName"
                    wire:loading.attr="disabled"
                    wire:target="regenerateName"
                    class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm transition hover:border-brand-sage hover:text-brand-sage disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <x-heroicon-o-arrow-path wire:loading.remove wire:target="regenerateName" class="h-4 w-4" />
                    <span wire:loading.remove wire:target="regenerateName">{{ __('Regenerate') }}</span>
                    <span wire:loading wire:target="regenerateName" class="inline-flex items-center gap-2">
                        <x-spinner variant="zinc" size="sm" />
                        {{ __('Regenerating…') }}
                    </span>
                </button>
            </div>
            <x-input-error :messages="$errors->get('form.name')" class="mt-2" />
        </section>

        <footer class="flex items-center justify-between border-t border-brand-ink/10 pt-6">
            <button type="button" wire:click="openDiscardDraftModal" class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-moss transition-colors hover:text-red-700">
                <x-heroicon-o-trash class="h-4 w-4" />
                {{ __('Discard draft') }}
            </button>
            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="next"
                class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-6 py-3 text-sm font-semibold text-brand-cream shadow-md shadow-brand-ink/15 transition-colors hover:bg-brand-forest disabled:cursor-wait disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="next">{{ __('Continue') }}</span>
                <span wire:loading wire:target="next" class="inline-flex items-center gap-2">
                    <x-spinner variant="cream" size="sm" />
                    {{ __('Saving…') }}
                </span>
                <x-heroicon-o-arrow-right wire:loading.remove wire:target="next" class="h-4 w-4" />
            </button>
        </footer>
    </form>

    @include('livewire.servers.create._discard-draft-modal')
</div>

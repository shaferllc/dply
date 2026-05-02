<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 space-y-8">
    <x-server-create-stepper :current="1" :reached="1" :mode="$form->mode" :hostKind="$form->custom_host_kind" />

    <form wire:submit.prevent="next" class="space-y-8">
        <header>
            <h1 class="text-2xl font-semibold text-brand-ink sm:text-3xl">{{ __('Create a server') }}</h1>
            <p class="mt-2 text-sm text-brand-moss">{{ __('Step 1 of :total — pick how you want to add this server and give it a name.', ['total' => $totalSteps]) }}</p>
            @if ($dockerHostHinted)
                <div class="mt-4 rounded-2xl border border-sky-200 bg-sky-50/70 px-5 py-4 text-sm text-slate-800">
                    {{ __('Detected a Docker-host launch path. Custom mode is preselected; you can switch back to a managed provider below.') }}
                </div>
            @endif
        </header>

        <section class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ __('How are you adding this server?') }}</h2>
            <div class="grid gap-3 sm:grid-cols-2">
                <button
                    type="button"
                    wire:click="chooseProviderMode"
                    @class([
                        'group rounded-2xl border-2 p-5 text-left transition-colors',
                        'border-sky-500 bg-sky-50' => $form->mode === 'provider',
                        'border-slate-200 bg-white hover:border-slate-300' => $form->mode !== 'provider',
                    ])
                >
                    <div class="flex items-start gap-3">
                        <span @class([
                            'mt-1 inline-flex h-5 w-5 items-center justify-center rounded-full border-2',
                            'border-sky-500 bg-sky-500 text-white' => $form->mode === 'provider',
                            'border-slate-300 bg-white' => $form->mode !== 'provider',
                        ])>
                            @if ($form->mode === 'provider')
                                <x-heroicon-s-check class="h-3 w-3" />
                            @endif
                        </span>
                        <span>
                            <span class="block text-base font-semibold text-slate-900">{{ __('Provision with a provider') }}</span>
                            <span class="mt-1 block text-sm text-slate-600">{{ __('We talk to DigitalOcean / AWS / Hetzner / etc. and bring up a fresh VM.') }}</span>
                        </span>
                    </div>
                </button>
                <button
                    type="button"
                    wire:click="chooseCustomMode"
                    @class([
                        'group rounded-2xl border-2 p-5 text-left transition-colors',
                        'border-sky-500 bg-sky-50' => $form->mode === 'custom',
                        'border-slate-200 bg-white hover:border-slate-300' => $form->mode !== 'custom',
                    ])
                >
                    <div class="flex items-start gap-3">
                        <span @class([
                            'mt-1 inline-flex h-5 w-5 items-center justify-center rounded-full border-2',
                            'border-sky-500 bg-sky-500 text-white' => $form->mode === 'custom',
                            'border-slate-300 bg-white' => $form->mode !== 'custom',
                        ])>
                            @if ($form->mode === 'custom')
                                <x-heroicon-s-check class="h-3 w-3" />
                            @endif
                        </span>
                        <span>
                            <span class="block text-base font-semibold text-slate-900">{{ __('Custom server (BYO)') }}</span>
                            <span class="mt-1 block text-sm text-slate-600">{{ __('Connect to a server you already have over SSH — no cloud APIs.') }}</span>
                        </span>
                    </div>
                </button>
            </div>
            <x-input-error :messages="$errors->get('form.mode')" class="mt-1" />
        </section>

        <section class="space-y-2">
            <x-input-label for="form_name" :value="__('Server name')" />
            <div class="flex gap-2">
                <x-text-input id="form_name" wire:model="form.name" type="text" class="block w-full" required autocomplete="off" />
                <button
                    type="button"
                    wire:click="regenerateName"
                    wire:loading.attr="disabled"
                    wire:target="regenerateName"
                    class="inline-flex min-w-[7.5rem] shrink-0 items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="regenerateName">{{ __('Regenerate') }}</span>
                    <span wire:loading wire:target="regenerateName" class="inline-flex items-center gap-2">
                        <x-spinner variant="zinc" size="sm" />
                        {{ __('Regenerating…') }}
                    </span>
                </button>
            </div>
            <p class="text-xs text-brand-mist">{{ __('Letters, digits, dot, underscore, and hyphen. Up to 64 characters.') }}</p>
            <x-input-error :messages="$errors->get('form.name')" class="mt-1" />
        </section>

        <footer class="flex items-center justify-between border-t border-zinc-100 pt-5">
            <button type="button" wire:click="openDiscardDraftModal" class="text-sm font-medium text-brand-moss hover:text-red-700">
                {{ __('Discard draft') }}
            </button>
            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="next"
                class="inline-flex items-center justify-center gap-2 rounded-xl bg-sky-600 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-sky-700 disabled:cursor-wait disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="next">{{ __('Continue') }}</span>
                <span wire:loading wire:target="next" class="inline-flex items-center gap-2">
                    <x-spinner variant="white" size="sm" />
                    {{ __('Saving…') }}
                </span>
                <x-heroicon-o-arrow-right wire:loading.remove wire:target="next" class="h-4 w-4" />
            </button>
        </footer>
    </form>

    @include('livewire.servers.create._discard-draft-modal')
</div>

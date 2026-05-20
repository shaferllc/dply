<div>
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-breadcrumb-trail :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Launch'), 'href' => route('launches.create'), 'icon' => 'rocket-launch'],
            ['label' => __('Serverless'), 'icon' => 'sparkles'],
        ]" />

        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 border-b border-brand-ink/10">
                <h1 class="text-xl font-bold text-brand-ink">{{ __('Create a serverless app') }}</h1>
                <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                    {{ __('Deploy an HTTP-triggered function to DigitalOcean Functions — no machine to provision. Point us at a repo and we handle the rest.') }}
                </p>
            </div>

            <x-livewire-validation-errors class="m-6 sm:m-8 mb-0" />

            @if ($credentials->isEmpty())
                <div class="p-6 sm:p-8">
                    <x-alert tone="warning">
                        {{ __('Connect a DigitalOcean credential first — serverless functions deploy through your DO account.') }}
                        <a href="{{ route('credentials.index') }}" wire:navigate class="font-semibold underline underline-offset-2">{{ __('Add credentials') }}</a>
                    </x-alert>
                </div>
            @else
                <form wire:submit="create" class="p-6 sm:p-8 space-y-6">
                    <div class="rounded-xl border border-brand-gold/30 bg-brand-gold/10 px-4 py-3 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-brand-ink">{{ __('New to serverless? Start from a demo.') }}</p>
                            <p class="text-xs text-brand-moss mt-0.5">{{ __('Prefills the form — just pick a region and credential, then Create.') }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" wire:click="loadPhpDemo"
                                    class="inline-flex items-center rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream hover:bg-brand-forest whitespace-nowrap">
                                {{ __('Load PHP demo') }}
                            </button>
                            <button type="button" wire:click="loadLaravelDemo"
                                    class="inline-flex items-center rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream hover:bg-brand-forest whitespace-nowrap">
                                {{ __('Load Laravel demo') }}
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-brand-ink">{{ __('Function name') }}</label>
                        <input type="text" wire:model="name" placeholder="my-api"
                               class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm focus:border-brand-gold focus:ring-1 focus:ring-brand-gold/40 focus:outline-none">
                    </div>

                    <div class="grid sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-brand-ink">{{ __('Git repository') }}</label>
                            <input type="text" wire:model="repo" placeholder="owner/repo"
                                   class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm focus:border-brand-gold focus:ring-1 focus:ring-brand-gold/40 focus:outline-none">
                            <p class="mt-1 text-xs text-brand-moss/80">{{ __('owner/repo or a full GitHub URL') }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-brand-ink">{{ __('Branch') }}</label>
                            <input type="text" wire:model="branch"
                                   class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm focus:border-brand-gold focus:ring-1 focus:ring-brand-gold/40 focus:outline-none">
                        </div>
                    </div>

                    <div class="grid sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-brand-ink">{{ __('Runtime') }}</label>
                            <select wire:model="runtime" class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm focus:border-brand-gold focus:ring-1 focus:ring-brand-gold/40 focus:outline-none">
                                @foreach ($runtimes as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-brand-ink">{{ __('Region') }}</label>
                            <select wire:model="region" class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm focus:border-brand-gold focus:ring-1 focus:ring-brand-gold/40 focus:outline-none">
                                @foreach ($regions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-brand-ink">{{ __('DO credential') }}</label>
                            <select wire:model="provider_credential_id" class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm focus:border-brand-gold focus:ring-1 focus:ring-brand-gold/40 focus:outline-none">
                                <option value="" disabled>{{ __('Select a credential') }}</option>
                                @foreach ($credentials as $credential)
                                    <option value="{{ $credential->id }}">{{ $credential->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="rounded-lg bg-brand-cream/50 border border-brand-ink/10 px-4 py-3 text-xs text-brand-moss">
                        {{ __('Billed at a flat per-function fee once the function is live — not as a server. See your billing page for the current rate.') }}
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <a href="{{ route('launches.create') }}" wire:navigate class="inline-flex items-center rounded-xl border-2 border-brand-ink/15 bg-white px-5 py-2.5 text-sm font-semibold text-brand-ink hover:border-brand-sage/40">
                            {{ __('Cancel') }}
                        </a>
                        <button type="submit"
                                wire:loading.attr="disabled" wire:target="create"
                                class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream shadow-md hover:bg-brand-forest disabled:opacity-70">
                            <svg wire:loading wire:target="create" class="animate-spin h-4 w-4" viewBox="0 0 24 24" fill="none">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4z"></path>
                            </svg>
                            <span wire:loading.remove wire:target="create">{{ __('Create & deploy') }}</span>
                            <span wire:loading wire:target="create">{{ __('Creating…') }}</span>
                        </button>
                    </div>
                </form>
            @endif
        </div>
    </div>
</div>

<div>
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-breadcrumb-trail :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Servers'), 'href' => route('servers.index'), 'icon' => 'server'],
            ['label' => __('Managed server'), 'icon' => 'sparkles'],
        ]" />

        <div class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-server class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Managed server') }}</p>
                    <h1 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Create a dply-hosted server') }}</h1>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('We provision and run the VM on dply\'s own infrastructure — no provider account to connect. You pay one all-in monthly price; we handle the underlying hosting.') }}
                    </p>
                </div>
            </div>

            <x-livewire-validation-errors class="m-6 sm:m-8 mb-0" />

            <form wire:submit="create" class="p-6 sm:p-8 space-y-6">
                <div>
                    <label for="name" class="block text-sm font-semibold text-brand-ink">{{ __('Server name') }}</label>
                    <input id="name" type="text" wire:model="name" autocomplete="off"
                           placeholder="{{ __('e.g. production-web') }}"
                           class="mt-1.5 block w-full rounded-lg border-brand-ink/15 text-sm focus:border-brand-gold focus:ring-brand-gold/40">
                </div>

                <div>
                    <label for="region" class="block text-sm font-semibold text-brand-ink">{{ __('Region') }}</label>
                    <select id="region" wire:model="region"
                            class="mt-1.5 block w-full rounded-lg border-brand-ink/15 text-sm focus:border-brand-gold focus:ring-brand-gold/40">
                        @foreach ($regions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <span class="block text-sm font-semibold text-brand-ink">{{ __('Size') }}</span>
                    <div class="mt-2 grid gap-3 sm:grid-cols-2">
                        @foreach ($sizes as $option)
                            <label class="flex cursor-pointer items-start justify-between gap-3 rounded-xl border-2 px-4 py-3 transition
                                          {{ $size === $option['slug'] ? 'border-brand-gold bg-brand-gold/10' : 'border-brand-ink/15 bg-white hover:border-brand-sage/40' }}">
                                <span class="min-w-0">
                                    <input type="radio" wire:model.live="size" value="{{ $option['slug'] }}" class="sr-only">
                                    <span class="block text-sm font-semibold text-brand-ink">{{ $option['label'] }}</span>
                                    <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">
                                        {{ $option['vcpu'] }} {{ __('vCPU') }} · {{ $option['ram_gb'] }} {{ __('GB RAM') }} · {{ $option['disk_gb'] }} {{ __('GB disk') }}
                                    </span>
                                </span>
                                <span class="shrink-0 text-right">
                                    <span class="block text-sm font-semibold text-brand-ink">${{ number_format($option['monthly_cents'] / 100, 2) }}</span>
                                    <span class="block text-[11px] text-brand-moss">{{ __('/mo') }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div>
                    <label for="install_profile" class="block text-sm font-semibold text-brand-ink">{{ __('Stack') }}</label>
                    <select id="install_profile" wire:model="install_profile"
                            class="mt-1.5 block w-full rounded-lg border-brand-ink/15 text-sm focus:border-brand-gold focus:ring-brand-gold/40">
                        @foreach ($profiles as $profile)
                            <option value="{{ $profile['id'] }}">{{ $profile['label'] }} — {{ $profile['summary'] }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('You can adjust runtimes, services, and webserver from the server workspace after it boots.') }}</p>
                </div>

                <div class="rounded-xl border border-brand-sage/30 bg-brand-sage/10 px-4 py-3 flex items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-brand-ink">{{ __('All-in price') }}</p>
                        <p class="text-xs text-brand-moss mt-0.5">{{ __('One monthly fee — hosting included. Replaces the per-server plan fee. Billed monthly while the server exists.') }}</p>
                    </div>
                    <div class="text-right shrink-0">
                        <p class="text-lg font-bold text-brand-ink">${{ number_format($selectedMonthlyCents / 100, 2) }}</p>
                        <p class="text-[11px] text-brand-moss">{{ __('/mo') }}</p>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <a href="{{ route('servers.index') }}" wire:navigate
                       class="text-sm font-semibold text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</a>
                    <button type="submit" wire:loading.attr="disabled" wire:target="create"
                            class="inline-flex items-center gap-2 rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest disabled:opacity-60">
                        <span wire:loading.remove wire:target="create">{{ __('Create server') }}</span>
                        <span wire:loading wire:target="create">{{ __('Starting…') }}</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

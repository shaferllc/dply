<section class="space-y-8">
    <div class="space-y-4">
        <div>
            <h4 id="install-profile-step-heading" class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('1. Choose an install profile') }}</h4>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Start with a preset and then fine-tune anything in advanced options.') }}</p>
        </div>

        <fieldset class="min-w-0 border-0 p-0">
            <legend class="sr-only">{{ __('Install profile') }}</legend>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($installProfiles as $profile)
                    @php
                        $profileIcon = $installProfileCardIcons[$profile['id']] ?? 'heroicon-o-squares-2x2';
                        $profileSelected = $form->install_profile === $profile['id'];
                    @endphp
                    <button
                        type="button"
                        wire:key="install-profile-{{ $profile['id'] }}"
                        wire:click="$set('form.install_profile', '{{ $profile['id'] }}')"
                        aria-pressed="{{ $profileSelected ? 'true' : 'false' }}"
                        class="group flex w-full gap-4 rounded-2xl border p-4 text-left transition-all focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-sage/60 sm:p-5 @if ($profileSelected) border-2 border-brand-sage/55 bg-white shadow-md shadow-brand-ink/10 ring-1 ring-brand-sage/25 @else border border-brand-ink/12 bg-white/95 hover:-translate-y-0.5 hover:border-brand-sage/40 hover:shadow-md @endif"
                    >
                        <span class="@if ($profileSelected) bg-brand-forest/12 text-brand-forest ring-brand-forest/25 @else bg-brand-sand/55 text-brand-forest ring-brand-ink/10 group-hover:bg-brand-sand/75 @endif flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl ring-1 transition">
                            <x-dynamic-component :component="$profileIcon" class="h-7 w-7 shrink-0" aria-hidden="true" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-base font-semibold leading-snug text-brand-ink">{{ $profile['label'] }}</span>
                            @if (! empty($profile['summary']))
                                <span class="mt-1.5 block text-sm leading-snug text-brand-moss line-clamp-3">{{ $profile['summary'] }}</span>
                            @endif
                        </span>
                        @if ($profileSelected)
                            <x-heroicon-m-check-circle class="h-6 w-6 shrink-0 text-brand-forest" aria-hidden="true" />
                        @endif
                    </button>
                @endforeach
            </div>
        </fieldset>
        <x-input-error :messages="$errors->get('install_profile')" class="mt-2" />

        @if ($selectedInstallProfile)
            <div class="rounded-2xl border border-brand-ink/10 bg-brand-cream/50 p-5 ring-1 ring-brand-ink/[0.06]">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-brand-ink">{{ $selectedInstallProfile['label'] }}</p>
                        <p class="mt-1 text-sm leading-6 text-brand-moss">{{ $selectedInstallProfile['summary'] ?? '' }}</p>
                    </div>
                    <span class="inline-flex shrink-0 items-center rounded-full bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss ring-1 ring-brand-ink/10">
                        {{ __('Preset') }}
                    </span>
                </div>
            </div>
        @endif
    </div>

    <div class="space-y-4">
        <div>
            <h4 id="server-role-step-heading" class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('2. Choose the server type') }}</h4>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Pick what this machine is mainly responsible for. We will adapt the default software stack to match.') }}</p>
        </div>

        <fieldset class="min-w-0 border-0 p-0">
            <legend class="sr-only">{{ __('Server type') }}</legend>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($provisionOptions['server_roles'] as $role)
                    @php
                        $roleIcon = $serverRoleCardIcons[$role['id']] ?? 'heroicon-o-server-stack';
                        $roleSelected = $form->server_role === $role['id'];
                    @endphp
                    <button
                        type="button"
                        wire:key="server-role-{{ $role['id'] }}"
                        wire:click="$set('form.server_role', '{{ $role['id'] }}')"
                        aria-pressed="{{ $roleSelected ? 'true' : 'false' }}"
                        class="group flex w-full gap-4 rounded-2xl border p-4 text-left transition-all focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-sage/60 sm:p-5 @if ($roleSelected) border-2 border-brand-sage/55 bg-white shadow-md shadow-brand-ink/10 ring-1 ring-brand-sage/25 @else border border-brand-ink/12 bg-white/95 hover:-translate-y-0.5 hover:border-brand-sage/40 hover:shadow-md @endif"
                    >
                        <span class="@if ($roleSelected) bg-brand-forest/12 text-brand-forest ring-brand-forest/25 @else bg-brand-sand/55 text-brand-forest ring-brand-ink/10 group-hover:bg-brand-sand/75 @endif flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl ring-1 transition">
                            <x-dynamic-component :component="$roleIcon" class="h-7 w-7 shrink-0" aria-hidden="true" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-base font-semibold leading-snug text-brand-ink">{{ $role['label'] }}</span>
                            @if (! empty($role['summary']))
                                <span class="mt-1.5 block text-sm leading-snug text-brand-moss line-clamp-3">{{ $role['summary'] }}</span>
                            @endif
                        </span>
                        @if ($roleSelected)
                            <x-heroicon-m-check-circle class="h-6 w-6 shrink-0 text-brand-forest" aria-hidden="true" />
                        @endif
                    </button>
                @endforeach
            </div>
        </fieldset>
        <x-input-error :messages="$errors->get('server_role')" class="mt-2" />

        @if ($selectedServerRole)
            <div class="rounded-2xl border border-brand-ink/10 bg-brand-cream/50 p-5 ring-1 ring-brand-ink/[0.06]">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-brand-ink">{{ $selectedServerRole['label'] }}</p>
                        <p class="mt-1 text-sm leading-6 text-brand-moss">{{ $selectedServerRole['summary'] ?? ($selectedServerRole['detail'] ?? '') }}</p>
                    </div>
                    <span class="inline-flex shrink-0 items-center rounded-full bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss ring-1 ring-brand-ink/10">
                        {{ __('Role') }}
                    </span>
                </div>

                @if ($serverRoleInstalls->isNotEmpty())
                    <div class="mt-4 border-t border-brand-ink/10 pt-4">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Default installs') }}</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($serverRoleInstalls as $install)
                                <span class="inline-flex items-center rounded-full bg-white px-2.5 py-1 text-xs font-medium text-brand-ink ring-1 ring-brand-ink/10">{{ $install }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </div>
</section>

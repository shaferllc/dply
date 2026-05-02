<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 space-y-8">
    <x-server-create-stepper :current="3" :reached="$reachedStep" :mode="$form->mode" :hostKind="$form->custom_host_kind" />

    <form wire:submit.prevent="next" class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(20rem,1fr)]">
      <div class="space-y-8 min-w-0">
        <header>
            <h1 class="text-2xl font-semibold text-brand-ink sm:text-3xl">{{ __('What it runs') }}</h1>
            <p class="mt-2 text-sm text-brand-moss">{{ __('Step 3 of :total — pick a stack preset, then tweak any defaults you want.', ['total' => $totalSteps]) }}</p>
        </header>

        @php
            $selectedInstallProfile = collect($installProfiles)->firstWhere('id', $form->install_profile);
            $selectedServerRole = collect($provisionOptions['server_roles'] ?? [])->firstWhere('id', $form->server_role);
        @endphp

        {{-- Install profile + Server role: rich-select dropdowns (compact, slick) --}}
        <section class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 sm:grid-cols-2">
            @include('livewire.servers.create._rich-select', [
                'id' => 'install_profile',
                'label' => __('Install profile'),
                'field' => 'form.install_profile',
                'value' => $form->install_profile,
                'options' => collect($installProfiles)->map(fn ($p) => [
                    'id' => (string) ($p['id'] ?? ''),
                    'label' => (string) ($p['label'] ?? ''),
                    'summary' => (string) ($p['summary'] ?? ''),
                ])->all(),
                'errorKey' => 'form.install_profile',
                'eyebrow' => __('Profile'),
                'placeholder' => __('Choose a preset'),
            ])
            @include('livewire.servers.create._rich-select', [
                'id' => 'server_role',
                'label' => __('Server role'),
                'field' => 'form.server_role',
                'value' => $form->server_role,
                'options' => collect($provisionOptions['server_roles'] ?? [])->map(fn ($r) => [
                    'id' => (string) ($r['id'] ?? ''),
                    'label' => (string) ($r['label'] ?? ''),
                    'summary' => (string) ($r['summary'] ?? ''),
                ])->all(),
                'errorKey' => 'form.server_role',
                'eyebrow' => __('Role'),
                'placeholder' => __('Choose a role'),
            ])
        </section>

        {{-- Stack details --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="text-base font-semibold text-slate-900">{{ __('Stack details') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ __('Pre-filled from the install profile and role. Override any of these before continuing.') }}</p>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                @include('livewire.servers.create._rich-select', [
                    'id' => 'webserver',
                    'label' => __('Web server'),
                    'field' => 'form.webserver',
                    'value' => $form->webserver,
                    'options' => $provisionOptions['webservers'] ?? [],
                    'errorKey' => 'form.webserver',
                ])
                @include('livewire.servers.create._rich-select', [
                    'id' => 'php_version',
                    'label' => __('PHP version'),
                    'field' => 'form.php_version',
                    'value' => $form->php_version,
                    'options' => $provisionOptions['php_versions'] ?? [],
                    'errorKey' => 'form.php_version',
                ])
                @include('livewire.servers.create._rich-select', [
                    'id' => 'database',
                    'label' => __('Database'),
                    'field' => 'form.database',
                    'value' => $form->database,
                    'options' => $provisionOptions['databases'] ?? [],
                    'errorKey' => 'form.database',
                ])
                @include('livewire.servers.create._rich-select', [
                    'id' => 'cache_service',
                    'label' => __('Cache service'),
                    'field' => 'form.cache_service',
                    'value' => $form->cache_service,
                    'options' => $provisionOptions['cache_services'] ?? [],
                    'errorKey' => 'form.cache_service',
                ])
            </div>
        </section>

        <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-100 pt-5">
            <button
                type="button"
                wire:click="openDiscardDraftModal"
                class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-rose-200 bg-white px-5 text-sm font-semibold text-rose-700 hover:bg-rose-50"
            >
                <x-heroicon-o-trash class="h-4 w-4" />
                {{ __('Discard draft') }}
            </button>
            <div class="flex items-center gap-3">
                <button
                    type="button"
                    wire:click="previous"
                    class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                >
                    <x-heroicon-o-arrow-left class="h-4 w-4" />
                    {{ __('Back') }}
                </button>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="next"
                    class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-sky-600 px-5 text-sm font-semibold text-white shadow-sm hover:bg-sky-700 disabled:cursor-wait disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="next">{{ __('Continue to review') }}</span>
                    <span wire:loading wire:target="next" class="inline-flex items-center gap-2">
                        <x-spinner variant="white" size="sm" />
                        {{ __('Saving…') }}
                    </span>
                    <x-heroicon-o-arrow-right wire:loading.remove wire:target="next" class="h-4 w-4" />
                </button>
            </div>
        </footer>
      </div>

      {{-- Sidebar: selected profile + role detail, install previews --}}
      <aside class="space-y-4 lg:sticky lg:top-6 lg:self-start">
        @if ($selectedInstallProfile)
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Install profile') }}</p>
                <p class="mt-2 text-sm font-semibold text-slate-900">{{ $selectedInstallProfile['label'] }}</p>
                @if (! empty($selectedInstallProfile['summary']))
                    <p class="mt-1 text-xs leading-5 text-slate-600">{{ $selectedInstallProfile['summary'] }}</p>
                @endif
            </div>
        @endif

        @if ($selectedServerRole)
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Server role') }}</p>
                <p class="mt-2 text-sm font-semibold text-slate-900">{{ $selectedServerRole['label'] }}</p>
                @if (! empty($selectedServerRole['summary']))
                    <p class="mt-1 text-xs leading-5 text-slate-600">{{ $selectedServerRole['summary'] }}</p>
                @endif
                @if (! empty($selectedServerRole['installs']) && is_array($selectedServerRole['installs']))
                    <div class="mt-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Will install') }}</p>
                        <ul class="mt-1.5 space-y-1 text-xs text-slate-600">
                            @foreach (array_slice($selectedServerRole['installs'], 0, 6) as $item)
                                <li>• {{ is_array($item) ? ($item['label'] ?? '') : $item }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif

        <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4 text-sm text-slate-700">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Tips') }}</p>
            <ul class="mt-2 space-y-1.5">
                <li>• {{ __('Picking a profile sets sensible defaults for the stack — you can override any.') }}</li>
                <li>• {{ __('The role determines what gets installed: e.g. load_balancer skips PHP, database skips the web stack, redis only installs Redis.') }}</li>
                <li>• {{ __('Only the Application and Worker roles install PHP and Composer; Supervisor is included on roles that need long-running processes.') }}</li>
            </ul>
        </div>
      </aside>
    </form>

    @include('livewire.servers.create._discard-draft-modal')
</div>

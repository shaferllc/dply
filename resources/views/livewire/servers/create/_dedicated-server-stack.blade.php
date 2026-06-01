{{--
  Step 3 stack summary when the operator already picked a dedicated server
  purpose on Step 2 (Redis, database node, worker, etc.).
  Required: $selectedServerRole, $form, $provisionOptions, $stepWhereRoute
  Optional: $dedicatedCacheEngineOptions
--}}
@php
    $roleId = (string) ($selectedServerRole['id'] ?? $form->server_role);
    $showDatabasePicker = $roleId === 'database' || $form->server_role === 'database';
    $showPhpPicker = $roleId === 'worker' || $form->server_role === 'worker';
    $showCacheEnginePicker = in_array($form->server_role, ['redis', 'valkey'], true);
    $cacheEngineOptions = collect($dedicatedCacheEngineOptions ?? [])
        ->filter(fn (array $row): bool => ($row['id'] ?? '') !== 'none')
        ->values();
    $selectedCacheEngine = $cacheEngineOptions->firstWhere('id', $form->cache_service);
    $packageLabels = $showCacheEnginePicker
        ? collect([$selectedCacheEngine['label'] ?? __('Redis'), 'UFW'])->filter()->values()
        : collect($selectedServerRole['installs'] ?? [])
            ->filter(fn ($item): bool => is_string($item) && $item !== '')
            ->values();
@endphp

<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-check-badge class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0 flex-1">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Stack') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('What we\'ll install') }}</h3>
            <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                {{ __('You chose a dedicated :role on the previous step. Pick the engine below — dply provisions only what that host needs.', ['role' => strtolower((string) ($selectedServerRole['label'] ?? __('server')))]) }}
            </p>
        </div>
    </div>

    <div class="space-y-6 p-6 sm:p-7">
        <div class="rounded-2xl border-2 border-brand-sage/40 bg-gradient-to-br from-brand-sage/10 via-white to-white p-5 ring-1 ring-brand-sage/20">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Server purpose') }}</p>
                    <p class="mt-1 text-lg font-semibold text-brand-ink">{{ $selectedServerRole['label'] ?? $roleId }}</p>
                    @if (! empty($selectedServerRole['summary']))
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ $selectedServerRole['summary'] }}</p>
                    @endif
                </div>
                <a
                    href="{{ $stepWhereRoute }}"
                    wire:navigate
                    class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/10 bg-white px-3 py-2 text-xs font-semibold text-brand-forest shadow-sm transition hover:border-brand-sage/40 hover:bg-brand-sand/30"
                >
                    {{ __('Change purpose') }}
                    <x-heroicon-m-arrow-left class="h-3.5 w-3.5" aria-hidden="true" />
                </a>
            </div>

            @if ($packageLabels->isNotEmpty())
                <div class="mt-5">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Packages') }}</p>
                    <ul class="mt-2 flex flex-wrap gap-2">
                        @foreach ($packageLabels as $package)
                            <li class="inline-flex items-center rounded-full bg-white px-3 py-1 text-xs font-medium text-brand-ink ring-1 ring-brand-ink/10">
                                {{ $package }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (! empty($selectedServerRole['does_not_include']))
                <p class="mt-4 text-xs leading-relaxed text-brand-mist">{{ $selectedServerRole['does_not_include'] }}</p>
            @endif
        </div>

        @if ($showCacheEnginePicker)
            @include('livewire.servers.create._dedicated-cache-options', [
                'form' => $form,
                'cacheEngineOptions' => $cacheEngineOptions,
            ])
        @endif

        @if ($showDatabasePicker)
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Database engine') }}</p>
                <p class="mt-1 text-xs text-brand-mist">{{ __('Pick the engine for this dedicated database host.') }}</p>
                <div class="mt-3">
                    @include('livewire.servers.create._rich-select', [
                        'id' => 'database',
                        'label' => __('Database'),
                        'field' => 'form.database',
                        'value' => $form->database,
                        'options' => $provisionOptions['databases'] ?? [],
                        'errorKey' => 'form.database',
                        'eyebrow' => __('Engine'),
                        'placeholder' => __('Choose a database'),
                    ])
                </div>
            </div>

            @include('livewire.servers.create._dedicated-database-options', [
                'form' => $form,
                'operatorPublicIp' => $operatorPublicIp ?? null,
            ])
        @endif

        @if ($showPhpPicker)
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('PHP runtime') }}</p>
                <p class="mt-1 text-xs text-brand-mist">{{ __('Queue workers need PHP on the host. Pick the version your jobs target.') }}</p>
                <div class="mt-3">
                    @include('livewire.servers.create._rich-select', [
                        'id' => 'php_version',
                        'label' => __('PHP version'),
                        'field' => 'form.php_version',
                        'value' => $form->php_version,
                        'options' => $provisionOptions['php_versions'] ?? [],
                        'errorKey' => 'form.php_version',
                        'eyebrow' => __('PHP'),
                        'placeholder' => __('Choose PHP'),
                    ])
                </div>
            </div>
        @endif
    </div>
</section>

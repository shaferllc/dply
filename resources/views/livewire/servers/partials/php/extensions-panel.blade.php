{{--
    Expandable extension manager for one PHP version, rendered inside that
    version's row in workspace-php.blade.php.

    State comes from the inventory probe, so a row can be installed-but-disabled
    (how you park Xdebug on staging). apt actions are queued jobs — the panel
    polls while $extensionTaskId is set because a PECL build can run for
    minutes; enable/disable complete inline.

    Expects: $version, $panel, plus the component's $extensionTaskId /
    $extensionTaskLabel / $customExtension.
--}}
<div
    id="php-extensions-{{ $version }}"
    class="mt-3 rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-3 sm:p-4"
    @if ($extensionTaskId !== null) wire:poll.3s="syncExtensionTask" @endif
>
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-brand-ink/10 pb-2">
        <div class="flex items-center gap-2">
            <x-heroicon-o-puzzle-piece class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
            <p class="text-xs font-semibold text-brand-ink">
                {{ __('Extensions for PHP :version', ['version' => $version]) }}
            </p>
            <span class="text-2xs text-brand-mist">
                {{ __(':installed installed · :enabled loaded', [
                    'installed' => $panel['installed_count'],
                    'enabled' => $panel['enabled_count'],
                ]) }}
            </span>
        </div>
        <p class="text-2xs text-brand-mist">
            {{ __('Shared by every site on this version. Changes restart PHP-FPM :version.', ['version' => $version]) }}
        </p>
    </div>

    @if ($extensionTaskId !== null)
        <div class="mt-3 flex items-center gap-2 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900">
            <x-spinner variant="forest" size="sm" />
            <span class="font-semibold">{{ __('Working on :label…', ['label' => $extensionTaskLabel]) }}</span>
            <span class="text-sky-800/80">{{ __('Running in the background — this panel updates when the server responds.') }}</span>
        </div>
    @endif

    @foreach ($panel['categories'] as $category)
        <div class="mt-3" wire:key="php-ext-cat-{{ $version }}-{{ $category['key'] }}">
            <p class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-sage">{{ $category['label'] }}</p>

            <ul class="mt-1.5 divide-y divide-brand-ink/5">
                @foreach ($category['rows'] as $ext)
                    @php
                        $extTargets = implode(',', [
                            "runPhpExtensionAction('install', '{$version}', '{$ext['id']}')",
                            "runPhpExtensionAction('uninstall', '{$version}', '{$ext['id']}')",
                            "runPhpExtensionAction('enable', '{$version}', '{$ext['id']}')",
                            "runPhpExtensionAction('disable', '{$version}', '{$ext['id']}')",
                        ]);
                        $busy = $extensionTaskId !== null;
                    @endphp

                    <li
                        class="flex flex-col gap-2 py-2 sm:flex-row sm:items-center sm:justify-between"
                        wire:key="php-ext-{{ $version }}-{{ $ext['id'] }}"
                        wire:loading.class="opacity-50"
                        wire:target="{{ $extTargets }}"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="font-mono text-xs font-semibold text-brand-ink">{{ $ext['id'] }}</span>
                                <span class="text-xs text-brand-moss">{{ $ext['label'] }}</span>

                                @if ($ext['is_enabled'])
                                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-emerald-800 ring-1 ring-emerald-200">
                                        <x-heroicon-m-check class="h-2.5 w-2.5" />
                                        {{ __('Loaded') }}
                                    </span>
                                @elseif ($ext['is_installed'])
                                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-amber-800 ring-1 ring-amber-200">
                                        {{ __('Installed, off') }}
                                    </span>
                                @endif

                                @if ($ext['bundled'])
                                    <span class="rounded-full bg-brand-sand/60 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss" title="{{ __('Ships inside php:version-common — enable or disable only.', ['version' => $version]) }}">
                                        {{ __('Bundled') }}
                                    </span>
                                @elseif ($ext['pecl'] && ! $ext['is_installed'])
                                    <span class="rounded-full bg-brand-sand/60 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss" title="{{ __('Falls back to a PECL source build if apt has no package for this version.') }}">
                                        {{ __('PECL fallback') }}
                                    </span>
                                @endif
                            </div>

                            <p class="mt-0.5 text-2xs text-brand-mist">{{ $ext['description'] }}</p>

                            @if ($ext['note'])
                                <p class="mt-0.5 text-2xs italic text-amber-700">{{ $ext['note'] }}</p>
                            @endif
                        </div>

                        @can('update', $server)
                            <div class="flex shrink-0 flex-wrap items-center gap-1.5">
                                @if (! $ext['is_installed'])
                                    <button
                                        type="button"
                                        wire:click="runPhpExtensionAction('install', '{{ $version }}', '{{ $ext['id'] }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="{{ $extTargets }}"
                                        @disabled($busy)
                                        class="inline-flex h-6 items-center gap-1 whitespace-nowrap rounded-md bg-brand-ink px-2 text-2xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <x-heroicon-m-arrow-down-tray class="h-3 w-3 shrink-0" />
                                        {{ __('Install') }}
                                    </button>
                                @else
                                    @if ($ext['is_enabled'])
                                        <button
                                            type="button"
                                            wire:click="runPhpExtensionAction('disable', '{{ $version }}', '{{ $ext['id'] }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="{{ $extTargets }}"
                                            @disabled($busy)
                                            class="inline-flex h-6 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-2xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {{ __('Disable') }}
                                        </button>
                                    @else
                                        <button
                                            type="button"
                                            wire:click="runPhpExtensionAction('enable', '{{ $version }}', '{{ $ext['id'] }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="{{ $extTargets }}"
                                            @disabled($busy)
                                            class="inline-flex h-6 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-2xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {{ __('Enable') }}
                                        </button>
                                    @endif

                                    @unless ($ext['bundled'])
                                        <button
                                            type="button"
                                            wire:click="runPhpExtensionAction('uninstall', '{{ $version }}', '{{ $ext['id'] }}')"
                                            wire:confirm="{{ __('Remove php:version-:ext? Sites on this server using PHP :version will lose it, and PHP-FPM restarts.', ['version' => $version, 'ext' => $ext['id']]) }}"
                                            wire:loading.attr="disabled"
                                            wire:target="{{ $extTargets }}"
                                            @disabled($busy)
                                            class="inline-flex h-6 items-center justify-center rounded-md border border-red-200 bg-white px-1.5 text-red-700 shadow-sm transition hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50"
                                            aria-label="{{ __('Remove :label', ['label' => $ext['label']]) }}"
                                        >
                                            <x-heroicon-o-trash class="h-3 w-3" />
                                        </button>
                                    @endunless
                                @endif
                            </div>
                        @endcan
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach

    @if ($panel['unlisted'] !== [])
        <div class="mt-3 border-t border-brand-ink/10 pt-2">
            <p class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-sage">{{ __('Also present on this server') }}</p>
            <p class="mt-1 font-mono text-2xs leading-relaxed text-brand-mist">{{ implode(', ', $panel['unlisted']) }}</p>
        </div>
    @endif

    @can('update', $server)
        <div class="mt-3 border-t border-brand-ink/10 pt-3">
            <label for="php-ext-custom-{{ $version }}" class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-sage">
                {{ __('Install another extension') }}
            </label>
            <div class="mt-1.5 flex flex-wrap items-center gap-2">
                <span class="font-mono text-xs text-brand-mist">php{{ $version }}-</span>
                <input
                    id="php-ext-custom-{{ $version }}"
                    type="text"
                    wire:model="customExtension"
                    wire:keydown.enter.prevent="installCustomExtension('{{ $version }}')"
                    placeholder="{{ __('e.g. grpc') }}"
                    class="h-7 w-40 rounded-md border-brand-ink/15 font-mono text-xs shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                    @disabled($extensionTaskId !== null)
                />
                <button
                    type="button"
                    wire:click="installCustomExtension('{{ $version }}')"
                    wire:loading.attr="disabled"
                    wire:target="installCustomExtension('{{ $version }}'),customExtension"
                    @disabled($extensionTaskId !== null)
                    class="inline-flex h-7 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <x-heroicon-m-arrow-down-tray class="h-3.5 w-3.5 shrink-0" wire:loading.remove wire:target="installCustomExtension('{{ $version }}')" />
                    <span wire:loading wire:target="installCustomExtension('{{ $version }}')" class="inline-flex h-4 w-4 items-center justify-center">
                        <x-spinner variant="forest" size="sm" />
                    </span>
                    {{ __('Install') }}
                </button>
            </div>
            <p class="mt-1 text-2xs text-brand-mist">
                {{ __('Any package apt offers for this version. Falls back to a PECL source build when apt has none.') }}
            </p>
        </div>
    @endcan
</div>

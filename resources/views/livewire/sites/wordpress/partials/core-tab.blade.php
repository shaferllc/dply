{{--
    Core: version and security status, updates (latest or security-only), a
    version picker with PHP gating, the database upgrade, the automatic-update
    policy, site language, and file integrity (verify + repair). Bedrock sites
    pin core in composer.json, so every file-changing control is withheld there.
--}}
@php
    $installed = data_get($core, 'version');
    $status = data_get($core, 'status');
    $updates = (array) data_get($core, 'updates', []);
    $latestUpdate = $updates[0]['version'] ?? data_get($core, 'latest');
    $minorUpdate = collect($updates)->firstWhere('type', 'minor')['version'] ?? null;
    $sitePhp = $this->site->server ? \App\Support\Servers\InstalledStack::fromMeta($this->site->server)->phpVersion : null;
    $target = collect($coreBranches)->firstWhere('version', $coreTargetVersion);
    $targetCompat = $target ? \App\Services\WordPress\CoreReleases::compatibility($target, $installed, $sitePhp) : null;
@endphp
<div>
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/[0.18] px-3 py-2.5 sm:px-4">
        <div class="min-w-0">
            <h3 class="text-sm font-semibold text-brand-ink">{{ __('WordPress core') }}</h3>
            <p class="mt-0.5 max-w-2xl text-xs leading-relaxed text-brand-moss">{{ __('Version, security status and updates, checked against WordPress.org.') }}</p>
        </div>
        @if ($coreLoaded)
            <button type="button" wire:click="loadCore" wire:loading.attr="disabled" wire:target="loadCore" class="ml-auto inline-flex shrink-0 items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">
                <span wire:loading.remove wire:target="loadCore" class="inline-flex items-center gap-1.5">
                    <x-heroicon-o-arrow-path class="h-4 w-4" aria-hidden="true" />
                    {{ __('Refresh') }}
                </span>
                <span wire:loading wire:target="loadCore" class="inline-flex items-center gap-1.5">
                    <x-spinner variant="forest" size="sm" />
                    {{ __('Checking…') }}
                </span>
            </button>
        @endif
    </div>

    @error('core')
        <p class="border-b border-rose-200/70 bg-rose-50/60 px-3 py-2 text-xs text-rose-700 sm:px-4">{{ $message }}</p>
    @enderror

    @if ($coreManagedByComposer)
        <p class="border-b border-sky-200/70 bg-sky-50/60 px-3 py-2 text-xs text-sky-900 sm:px-4">
            {{ __('Bedrock site: WordPress core is the roots/wordpress Composer package. Change its version in composer.json and deploy — updating it here would be undone by the next composer install.') }}
        </p>
    @endif

    @if (! $coreLoaded)
        <div wire:init="loadCore" class="flex items-center justify-center gap-2 px-6 py-12 text-sm text-brand-moss">
            <x-spinner variant="forest" size="sm" />
            {{ __('Checking WordPress core…') }}
        </div>
    @else
        {{-- 1. Version + security status --}}
        <div class="grid gap-px border-b border-brand-ink/10 bg-brand-ink/10 sm:grid-cols-2">
            <div class="bg-white px-3 py-3 sm:px-4">
                <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Installed version') }}</p>
                <div class="mt-1 flex flex-wrap items-center gap-2">
                    <p class="font-mono text-lg font-semibold text-brand-ink">{{ $installed ?: __('Unknown') }}</p>
                    @if ($status)
                        <span @class([
                            'rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide ring-1',
                            'bg-emerald-50 text-emerald-800 ring-emerald-200' => $status === 'latest',
                            'bg-amber-50 text-amber-800 ring-amber-200' => $status === 'outdated',
                            'bg-rose-50 text-rose-800 ring-rose-200' => $status === 'insecure',
                        ])>{{ match ($status) { 'latest' => __('latest'), 'outdated' => __('outdated'), default => __('insecure') } }}</span>
                    @endif
                </div>
                @if ($status === 'insecure')
                    <p class="mt-1 text-xs text-rose-700">{{ __('WordPress.org lists this version as having known security issues. Update to a patched release.') }}</p>
                @endif
            </div>
            <div class="bg-white px-3 py-3 sm:px-4">
                <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Update status') }}</p>
                @if (data_get($core, 'update_available'))
                    <p class="mt-1 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-ink">
                        <span class="rounded-full bg-brand-gold/20 px-2 py-0.5 text-xs">{{ __('Update available') }}</span>
                        @if ($latestUpdate)
                            <span class="font-mono text-brand-moss">→ {{ $latestUpdate }}</span>
                        @endif
                    </p>
                @else
                    <p class="mt-1 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-forest">
                        <x-heroicon-m-check-circle class="h-4 w-4" aria-hidden="true" />
                        {{ __('Up to date') }}
                    </p>
                @endif
            </div>
        </div>

        {{-- 2. Update: latest, or stay on this branch with security releases only --}}
        @if (data_get($core, 'update_available') && $canMutate && ! $coreManagedByComposer)
            <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 px-3 py-3 sm:px-4">
                <x-spinner-button size="xs" variant="primary" type="button" icon="heroicon-o-arrow-up-circle" target="updateCore" wire:click="updateCore">
                    {{ __('Update to :v', ['v' => $latestUpdate]) }}
                </x-spinner-button>
                @if ($minorUpdate && $minorUpdate !== $latestUpdate)
                    <x-spinner-button size="xs" variant="secondary" type="button" icon="heroicon-o-shield-check" target="updateCoreMinor" wire:click="updateCoreMinor">
                        {{ __('Security release only (:v)', ['v' => $minorUpdate]) }}
                    </x-spinner-button>
                @endif
                <p class="w-full text-2xs text-brand-mist">{{ __('Updates queue and apply in the background — refresh to confirm, then run the database upgrade below.') }}</p>
            </div>
        @endif

        {{-- 3. Version picker: the latest release of each branch --}}
        @if ($canMutate && ! $coreManagedByComposer && $coreBranches !== [])
            <div class="border-b border-brand-ink/10 px-3 py-3 sm:px-4">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Switch version') }}</p>
                <p class="mt-0.5 max-w-2xl text-xs text-brand-moss">{{ __('Each option is the latest security-patched release of its branch.') }}</p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <select wire:model.live="coreTargetVersion" aria-label="{{ __('WordPress version') }}" class="rounded-md border-brand-ink/15 py-1 text-xs shadow-sm focus:border-brand-forest focus:ring-brand-forest">
                        <option value="">{{ __('Choose a version…') }}</option>
                        @foreach ($coreBranches as $i => $release)
                            <option value="{{ $release['version'] }}" @disabled($release['version'] === $installed)>
                                {{ $release['version'] }}{{ $i === 0 ? ' — '.__('latest') : '' }}{{ $release['version'] === $installed ? ' — '.__('installed') : '' }} · PHP {{ $release['php'] }}+
                            </option>
                        @endforeach
                    </select>
                    <x-spinner-button size="xs" variant="secondary" type="button" target="confirmCoreVersionChange" wire:click="confirmCoreVersionChange" :disabled="! $target || $targetCompat['blockers'] !== []">
                        {{ __('Switch…') }}
                    </x-spinner-button>
                </div>
                @if ($targetCompat)
                    @foreach ($targetCompat['blockers'] as $blocker)
                        <p class="mt-2 rounded-md bg-rose-50 px-2 py-1 text-xs text-rose-800 ring-1 ring-rose-200">{{ $blocker }}</p>
                    @endforeach
                    @foreach ($targetCompat['warnings'] as $warning)
                        <p class="mt-2 rounded-md bg-amber-50 px-2 py-1 text-xs text-amber-900 ring-1 ring-amber-200">{{ $warning }}</p>
                    @endforeach
                @endif
            </div>
        @endif

        {{-- 4. Database upgrade --}}
        @if ($canMutate)
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 px-3 py-3 sm:px-4">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Database upgrade') }}</p>
                    <p class="mt-0.5 max-w-2xl text-xs text-brand-moss">{{ __('After a core update WordPress waits for the next wp-admin visit to migrate its tables. Run it now instead.') }}</p>
                </div>
                <x-spinner-button size="xs" variant="secondary" type="button" icon="heroicon-o-circle-stack" target="updateCoreDatabase" wire:click="updateCoreDatabase">{{ __('Upgrade database') }}</x-spinner-button>
            </div>
        @endif

        {{-- 5. Automatic updates (wp-config constant; admin/owner) --}}
        @if ($canDestroy && ! $coreManagedByComposer)
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 px-3 py-3 sm:px-4" wire:init="loadCoreAutoUpdate">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-brand-ink">
                        {{ __('Automatic core updates') }}
                        @if ($coreAutoUpdate === 'default')
                            <span class="ml-1 text-2xs font-normal text-brand-mist">{{ __('WordPress default (security releases only)') }}</span>
                        @elseif ($coreAutoUpdate === null)
                            <span class="ml-1 text-2xs font-normal text-brand-mist">{{ __('not read yet') }}</span>
                        @endif
                    </p>
                    <p class="mt-0.5 max-w-2xl text-xs text-brand-moss">{{ __('Sets WP_AUTO_UPDATE_CORE in wp-config.php. Security-only is the safe default; "All" also takes major releases.') }}</p>
                </div>
                <div class="inline-flex rounded-md border border-brand-ink/10 p-0.5">
                    @foreach (['off' => __('Off'), 'minor' => __('Security only'), 'all' => __('All')] as $mode => $modeLabel)
                        <button type="button" wire:click="setCoreAutoUpdate('{{ $mode }}')" wire:loading.attr="disabled" wire:target="setCoreAutoUpdate" @class([
                            'rounded px-2 py-0.5 text-2xs font-semibold',
                            'bg-brand-ink text-brand-cream' => $coreAutoUpdate === $mode,
                            'text-brand-moss hover:bg-brand-sand/40' => $coreAutoUpdate !== $mode,
                        ])>{{ $modeLabel }}</button>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- 6. Site language --}}
        <div class="border-b border-brand-ink/10 px-3 py-3 sm:px-4">
            @php $activeLanguage = collect($coreLanguages)->firstWhere('status', 'active'); @endphp
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-brand-ink">
                        {{ __('Site language') }}
                        @if ($activeLanguage)
                            <span class="ml-1 text-2xs font-normal text-brand-mist">{{ $activeLanguage['name'] }} ({{ $activeLanguage['code'] }})</span>
                        @endif
                    </p>
                    <p class="mt-0.5 max-w-2xl text-xs text-brand-moss">{{ __('Downloads the language pack if needed and switches the site to it.') }}</p>
                </div>
                @unless ($coreLanguagesLoaded)
                    <x-spinner-button size="xs" variant="secondary" type="button" icon="heroicon-o-language" target="loadCoreLanguages" wire:click="loadCoreLanguages">{{ __('Load languages') }}</x-spinner-button>
                @endunless
            </div>
            @if ($coreLanguagesLoaded && $canMutate && $coreLanguages !== [])
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <select wire:model="coreLanguageInstall" aria-label="{{ __('Language') }}" class="rounded-md border-brand-ink/15 py-1 text-xs shadow-sm focus:border-brand-forest focus:ring-brand-forest">
                        <option value="">{{ __('Choose a language…') }}</option>
                        @foreach (collect($coreLanguages)->sortBy('name') as $language)
                            <option value="{{ $language['code'] }}" @disabled($language['status'] === 'active')>
                                {{ $language['name'] }}{{ $language['native'] && $language['native'] !== $language['name'] ? ' — '.$language['native'] : '' }}{{ $language['status'] === 'installed' ? ' ✓' : '' }}
                            </option>
                        @endforeach
                    </select>
                    <x-spinner-button size="xs" variant="primary" type="button" target="installCoreLanguage" wire:click="installCoreLanguage">{{ __('Install & activate') }}</x-spinner-button>
                </div>
            @endif
        </div>
    @endif

    {{-- 7. Integrity: verify against WordPress.org checksums, and repair by
         re-laying the official files for the installed version. --}}
    <div class="px-3 py-3 sm:px-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Core file integrity') }}</p>
                <p class="mt-0.5 max-w-2xl text-xs text-brand-moss">{{ __('Verify compares every core file against the official checksums. Repair re-downloads the installed version over the core files — themes, plugins and uploads are not touched.') }}</p>
            </div>
            <div class="flex items-center gap-2">
                <x-spinner-button size="xs" variant="secondary" type="button" icon="heroicon-o-finger-print" target="verifyChecksums" wire:click="verifyChecksums">{{ __('Verify') }}</x-spinner-button>
                @if ($canMutate && ! $coreManagedByComposer && $installed)
                    <x-spinner-button size="xs" variant="secondary" type="button" icon="heroicon-o-wrench" target="confirmRepairCore" wire:click="confirmRepairCore">{{ __('Repair…') }}</x-spinner-button>
                @endif
            </div>
        </div>

        @if ($checksumReport !== null)
            <pre class="mt-3 max-h-56 overflow-auto rounded-md bg-brand-sand/50 p-3 font-mono text-2xs leading-relaxed text-brand-ink ring-1 ring-inset ring-brand-ink/10">{{ $checksumReport }}</pre>
        @endif
    </div>
</div>

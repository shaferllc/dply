{{--
    Hardening: a read-only security scan, the wp-config opinions, and one-click
    fixes. The scan reads the site's real state rather than trusting meta —
    meta once claimed wp-cron was disabled on sites where it was not.
--}}
@php
    $hardeningOpinions = collect(data_get($site->meta, 'scaffold.hardening', []))->keyBy('key');
    $opinions = [
        'disallow_file_edit' => [
            'title' => __('Disallow in-admin file editor'),
            'description' => __('Removes the Plugins / Themes file editor from wp-admin. Common attack vector for compromised admin accounts.'),
            'wp_constant' => 'DISALLOW_FILE_EDIT',
        ],
        'force_ssl_admin' => [
            'title' => __('Force SSL on /wp-admin'),
            'description' => __('Refuses unencrypted login + admin pages. Required for the placeholder URL since it ships with HTTPS.'),
            'wp_constant' => 'FORCE_SSL_ADMIN',
        ],
        'disallow_file_mods' => [
            'title' => __('Lock plugin & theme installs'),
            'description' => __('Blocks installing and updating plugins and themes from wp-admin — only dply (wp-cli) can. Also stops WordPress\'s own automatic updates, so keep up with the Plugins and Themes tabs.'),
            'wp_constant' => 'DISALLOW_FILE_MODS',
        ],
    ];
    $scanTone = [
        'pass' => ['bg-emerald-50 text-emerald-800 ring-emerald-200', 'heroicon-m-check-circle'],
        'warn' => ['bg-amber-50 text-amber-800 ring-amber-200', 'heroicon-m-exclamation-triangle'],
        'fail' => ['bg-rose-50 text-rose-800 ring-rose-200', 'heroicon-m-x-circle'],
        'unknown' => ['bg-brand-ink/[0.04] text-brand-moss ring-brand-ink/10', 'heroicon-m-question-mark-circle'],
    ];
@endphp
<div>
    @error('hardening')
        <p class="border-b border-rose-200/70 bg-rose-50/60 px-3 py-2 text-xs text-rose-700 sm:px-4">{{ $message }}</p>
    @enderror

    {{-- 1. Security scan --}}
    @if ($canMutate)
        <div class="border-b border-brand-ink/10">
            <div class="flex flex-wrap items-center justify-between gap-3 bg-brand-sand/[0.18] px-3 py-2.5 sm:px-4">
                <div class="min-w-0">
                    <h3 class="text-sm font-semibold text-brand-ink">{{ __('Security scan') }}</h3>
                    <p class="mt-0.5 max-w-2xl text-xs leading-relaxed text-brand-moss">{{ __('Core version, vulnerable plugins, unused themes, risky accounts, open registration and wp-config settings — about ten read-only checks over SSH.') }}</p>
                </div>
                <x-spinner-button size="xs" variant="primary" type="button" icon="heroicon-o-shield-check" target="runSecurityScan" wire:click="runSecurityScan">{{ $securityScan === null ? __('Run scan') : __('Scan again') }}</x-spinner-button>
            </div>
            @if ($securityScan !== null)
                @php $counts = collect($securityScan)->countBy('status'); @endphp
                <div class="flex flex-wrap gap-2 px-3 pt-2.5 text-2xs sm:px-4">
                    @foreach (['fail' => __('failing'), 'warn' => __('to review'), 'pass' => __('passing'), 'unknown' => __('unknown')] as $status => $label)
                        @if (($counts[$status] ?? 0) > 0)
                            <span class="rounded-full px-2 py-0.5 font-semibold ring-1 {{ $scanTone[$status][0] }}">{{ $counts[$status] }} {{ $label }}</span>
                        @endif
                    @endforeach
                </div>
                <ul class="divide-y divide-brand-ink/10 px-3 py-1.5 sm:px-4">
                    @foreach (collect($securityScan)->sortBy(fn ($c) => array_search($c['status'], ['fail', 'warn', 'unknown', 'pass'], true)) as $check)
                        <li class="flex items-start gap-2 py-2" wire:key="scan-{{ $check['key'] }}">
                            <x-dynamic-component :component="$scanTone[$check['status']][1]" @class(['mt-0.5 h-4 w-4 shrink-0', 'text-emerald-600' => $check['status'] === 'pass', 'text-amber-600' => $check['status'] === 'warn', 'text-rose-600' => $check['status'] === 'fail', 'text-brand-mist' => $check['status'] === 'unknown']) aria-hidden="true" />
                            <div class="min-w-0">
                                <p class="text-xs font-semibold text-brand-ink">{{ $check['label'] }}</p>
                                <p class="text-xs text-brand-moss">{{ $check['detail'] }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    {{-- 2. wp-config opinions --}}
    <div class="border-b border-brand-ink/10">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/[0.18] px-3 py-2.5 sm:px-4">
            <div class="min-w-0">
                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Hardening defaults') }}</h3>
                <p class="mt-0.5 max-w-2xl text-xs leading-relaxed text-brand-moss">{{ __('wp-config.php settings dply manages. Flip any of them if your site has a specific reason — your audit log records every change.') }}</p>
            </div>
        </div>

        <div class="divide-y divide-brand-ink/10">
            @foreach ($opinions as $key => $copy)
                @php $enabled = (bool) ($hardeningOpinions[$key]['enabled'] ?? false); @endphp
                <div class="flex items-start justify-between gap-4 px-3 py-2.5 sm:px-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-sm font-semibold text-brand-ink">{{ $copy['title'] }}</p>
                            <span class="rounded bg-brand-ink/[0.04] px-1.5 py-0.5 font-mono text-2xs text-brand-moss">{{ $copy['wp_constant'] }}</span>
                        </div>
                        <p class="mt-0.5 text-xs text-brand-moss">{{ $copy['description'] }}</p>
                    </div>
                    <button
                        type="button"
                        wire:click="toggleHardening('{{ $key }}')"
                        wire:loading.attr="disabled"
                        wire:target="toggleHardening"
                        @class([
                            'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors',
                            'bg-brand-sage' => $enabled,
                            'bg-brand-mist/40' => ! $enabled,
                        ])
                        aria-pressed="{{ $enabled ? 'true' : 'false' }}"
                        aria-label="{{ $copy['title'] }}"
                    >
                        <span @class([
                            'inline-block h-5 w-5 transform rounded-full bg-white shadow transition-transform',
                            'translate-x-5' => $enabled,
                            'translate-x-1' => ! $enabled,
                        ])></span>
                    </button>
                </div>
            @endforeach

            {{-- Not a toggle: the constant alone, with no crontab entry behind
                 it, stops every scheduled task. The Cron tab does both halves. --}}
            <div class="flex items-start justify-between gap-4 px-3 py-2.5 sm:px-4">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-sm font-semibold text-brand-ink">{{ __('System cron instead of wp-cron') }}</p>
                        <span class="rounded bg-brand-ink/[0.04] px-1.5 py-0.5 font-mono text-2xs text-brand-moss">DISABLE_WP_CRON</span>
                    </div>
                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Managed from the Cron tab, which installs the crontab entry before disabling wp-cron.') }}</p>
                </div>
                <button type="button" wire:click="$set('tab', 'cron')" class="shrink-0 rounded-md border border-brand-ink/15 px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Open Cron tab') }}</button>
            </div>
        </div>
    </div>

    {{-- 3–5. One-click fixes --}}
    @if ($canMutate)
        <div class="px-3 py-3 sm:px-4">
            <p class="text-sm font-semibold text-brand-ink">{{ __('Quick fixes') }}</p>
            <div class="mt-2 grid gap-2 sm:grid-cols-3">
                <button type="button" wire:click="lockDownRegistration" wire:loading.attr="disabled" wire:target="lockDownRegistration" class="rounded-md border border-brand-ink/10 bg-white px-2.5 py-2 text-left transition hover:bg-brand-sand/30">
                    <span class="block text-xs font-semibold text-brand-ink">{{ __('Lock down registration') }}</span>
                    <span class="block text-2xs text-brand-mist">{{ __('Close sign-ups and reset the default role to subscriber.') }}</span>
                </button>
                <button type="button" wire:click="installLoginProtection" wire:loading.attr="disabled" wire:target="installLoginProtection" class="rounded-md border border-brand-ink/10 bg-white px-2.5 py-2 text-left transition hover:bg-brand-sand/30">
                    <span class="block text-xs font-semibold text-brand-ink">{{ __('Add login protection') }}</span>
                    <span class="block text-2xs text-brand-mist">{{ __('Installs Limit Login Attempts Reloaded to slow password guessing.') }}</span>
                </button>
                <button type="button" wire:click="resetFilePermissions" wire:loading.attr="disabled" wire:target="resetFilePermissions" class="rounded-md border border-brand-ink/10 bg-white px-2.5 py-2 text-left transition hover:bg-brand-sand/30">
                    <span class="block text-xs font-semibold text-brand-ink">{{ __('Reset file permissions') }}</span>
                    <span class="block text-2xs text-brand-mist">{{ __('Re-applies dply\'s ownership and modes — after a manual upload or a plugin that chmods.') }}</span>
                </button>
            </div>
        </div>
    @endif
</div>

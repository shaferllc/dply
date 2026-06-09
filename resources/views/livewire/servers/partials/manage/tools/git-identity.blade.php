@php
    $gitIdentityBusy = $activeToolActionOps['set_deploy_git_identity'] ?? null;
    $gitDefaults = is_array($tool['identity_defaults'] ?? null) ? $tool['identity_defaults'] : [];
@endphp

<details class="group mt-2 overflow-hidden rounded-lg border border-brand-ink/10 bg-brand-sand/15 open:border-brand-sage/30 open:bg-white open:shadow-sm">
    <summary
        class="flex cursor-pointer list-none items-center justify-between gap-3 px-3 py-2.5 text-xs transition hover:bg-brand-sand/40 marker:content-none group-open:bg-brand-sage/8 group-open:hover:bg-brand-sage/10 [&::-webkit-details-marker]:hidden"
        aria-label="{{ __('Deploy user identity — click to edit') }}"
    >
        <span class="inline-flex min-w-0 items-center gap-1.5">
            <x-heroicon-o-chevron-right class="h-3.5 w-3.5 shrink-0 text-brand-mist transition group-open:rotate-90" aria-hidden="true" />
            <span class="font-semibold text-brand-ink">{{ __('Deploy user identity') }}</span>
            @if ($tool['identity_name'] || $tool['identity_email'])
                <span class="truncate font-normal text-brand-moss">
                    —
                    @if ($tool['identity_name'] && $tool['identity_email'])
                        {{ $tool['identity_name'] }} &lt;{{ $tool['identity_email'] }}&gt;
                    @elseif ($tool['identity_name'])
                        {{ $tool['identity_name'] }}
                    @else
                        &lt;{{ $tool['identity_email'] }}&gt;
                    @endif
                </span>
            @endif
        </span>
        @if ($opsReady && ! $isDeployer)
            <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-white px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-brand-moss ring-1 ring-brand-ink/10 transition group-open:hidden group-hover:text-brand-ink group-hover:ring-brand-sage/35">
                <x-heroicon-o-pencil-square class="h-3 w-3 shrink-0" aria-hidden="true" />
                {{ __('Click to edit') }}
            </span>
            <span class="hidden shrink-0 items-center gap-1 text-[10px] font-semibold uppercase tracking-[0.12em] text-brand-forest group-open:inline-flex">
                <x-heroicon-o-pencil-square class="h-3 w-3 shrink-0" aria-hidden="true" />
                {{ __('Editing') }}
            </span>
        @endif
    </summary>
    <div class="space-y-3 border-t border-brand-ink/10 px-3 py-3">
        @if ($opsReady && ! $isDeployer)
            @if ($gitIdentityBusy)
                <span class="inline-flex items-center gap-1.5 rounded-lg border border-brand-sage/30 bg-brand-sage/10 px-2.5 py-1.5 text-xs font-medium text-brand-forest">
                    <x-spinner variant="forest" size="sm" />
                    {{ $gitIdentityBusy['message'] }}
                </span>
            @endif
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label for="git-deploy-identity-name-tools" class="sr-only">{{ __('Git user name') }}</label>
                    <input
                        id="git-deploy-identity-name-tools"
                        type="text"
                        wire:model="git_deploy_identity_name"
                        @disabled($gitIdentityBusy !== null)
                        class="block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30 disabled:cursor-not-allowed disabled:opacity-60"
                        placeholder="{{ $gitDefaults['name'] ?? __('Name') }}"
                    />
                    @error('git_deploy_identity_name')
                        <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="git-deploy-identity-email-tools" class="sr-only">{{ __('Git user email') }}</label>
                    <input
                        id="git-deploy-identity-email-tools"
                        type="email"
                        wire:model="git_deploy_identity_email"
                        @disabled($gitIdentityBusy !== null)
                        class="block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30 disabled:cursor-not-allowed disabled:opacity-60"
                        placeholder="{{ $gitDefaults['email'] ?? __('Email') }}"
                    />
                    @error('git_deploy_identity_email')
                        <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
                    @enderror
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    wire:click="saveDeployGitIdentity"
                    wire:loading.attr="disabled"
                    wire:target="saveDeployGitIdentity,applyDefaultDeployGitIdentity"
                    @disabled($gitIdentityBusy !== null)
                    class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="saveDeployGitIdentity,applyDefaultDeployGitIdentity" class="inline-flex items-center gap-2">
                        <x-heroicon-o-check class="h-4 w-4" aria-hidden="true" />
                        {{ __('Save identity') }}
                    </span>
                    <span wire:loading wire:target="saveDeployGitIdentity,applyDefaultDeployGitIdentity" class="inline-flex items-center gap-2">
                        <x-spinner variant="forest" size="sm" />
                        {{ __('Saving…') }}
                    </span>
                </button>
                <button
                    type="button"
                    wire:click="applyDefaultDeployGitIdentity"
                    wire:loading.attr="disabled"
                    wire:target="saveDeployGitIdentity,applyDefaultDeployGitIdentity"
                    @disabled($gitIdentityBusy !== null)
                    class="text-xs font-semibold text-brand-moss hover:text-brand-ink disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {{ __('Use Dply default') }}
                </button>
            </div>
            <p class="text-[11px] leading-relaxed text-brand-moss">
                {{ __('Used for git commits as the deploy user. Default: :name &lt;:email&gt;.', [
                    'name' => $gitDefaults['name'] ?? '—',
                    'email' => $gitDefaults['email'] ?? '—',
                ]) }}
            </p>
        @elseif ($tool['identity_name'] || $tool['identity_email'])
            <p class="font-mono text-xs text-brand-ink">
                @if ($tool['identity_name'] && $tool['identity_email'])
                    {{ $tool['identity_name'] }} &lt;{{ $tool['identity_email'] }}&gt;
                @elseif ($tool['identity_name'])
                    {{ $tool['identity_name'] }}
                @else
                    &lt;{{ $tool['identity_email'] }}&gt;
                @endif
            </p>
        @else
            <p class="text-xs text-brand-moss">{{ __('Not set on the last probe.') }}</p>
        @endif
    </div>
</details>

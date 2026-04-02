@php
    $workspaceVariableCount = $site->workspace?->variables?->count() ?? 0;
    $siteVariableCount = $site->environmentVariables->count();
@endphp

<section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-slate-900">{{ __('Shared environment inventory') }}</h2>
        <p class="mt-1 text-sm text-slate-600">{{ __('Use this page to manage the inputs that feed the shared deployment contract. Dply merges project variables, site variables, and the encrypted .env draft into the runtime-specific delivery path for this site.') }}</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Project variables') }}</p>
            <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $workspaceVariableCount }}</p>
            <p class="mt-1 text-sm text-slate-600">{{ __('Inherited from the project workspace.') }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Site variables') }}</p>
            <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $siteVariableCount }}</p>
            <p class="mt-1 text-sm text-slate-600">{{ __('Scoped to this site and selected environment group.') }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Final inventory') }}</p>
            <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $secretConfigEntries->count() }}</p>
            <p class="mt-1 text-sm text-slate-600">{{ __('Shared secrets and config values visible in Deployment foundation.') }}</p>
        </div>
    </div>

    <div class="rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950">
        <p class="font-medium">{{ __('Runtime delivery') }}</p>
        <p class="mt-1">{{ $secretDeliveryLabel }}</p>
    </div>

    @if ($site->workspace && $site->workspace->variables->isNotEmpty())
        <div class="rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950">
            <p class="font-medium">{{ __('Inherited project variables') }}</p>
            <p class="mt-1">{{ __('These values are merged into the final .env for this site. Keep shared values on the project, then add a site variable only when this site needs an override.') }}</p>
        </div>
    @endif

    @if ($site->environmentVariables->isNotEmpty())
        <ul class="divide-y divide-slate-200 text-sm">
            @foreach ($site->environmentVariables as $variable)
                <li class="flex justify-between gap-3 py-3">
                    <span><span class="font-mono">{{ $variable->env_key }}</span> <span class="text-slate-500">({{ $variable->environment }})</span> = <span class="text-slate-500">••••</span></span>
                    <button type="button" wire:click="deleteEnvironmentVariable({{ $variable->id }})" class="text-red-700 hover:underline">{{ __('Remove') }}</button>
                </li>
            @endforeach
        </ul>
    @endif

    <form wire:submit="addEnvironmentVariable" class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div>
            <x-input-label for="new_env_key" value="KEY" />
            <x-text-input id="new_env_key" wire:model="new_env_key" class="mt-1 font-mono text-sm" placeholder="APP_DEBUG" />
            <x-input-error :messages="$errors->get('new_env_key')" class="mt-1" />
        </div>
        <div>
            <x-input-label for="new_env_value" value="Value" />
            <x-text-input id="new_env_value" wire:model="new_env_value" class="mt-1 font-mono text-sm" type="password" autocomplete="off" />
        </div>
        <div>
            <x-input-label for="new_env_environment" value="Environment" />
            <x-text-input id="new_env_environment" wire:model="new_env_environment" class="mt-1 text-sm" />
        </div>
        <div class="sm:col-span-3">
            <x-primary-button type="submit">{{ __('Save variable') }}</x-primary-button>
        </div>
    </form>
</section>

<section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-slate-900">{{ __('Environment (.env)') }}</h2>
        <p class="mt-1 text-sm text-slate-600">
            @if ($supportsEnvPush)
                {{ __('Draft is stored encrypted. Push merges project variables, site key/value variables for the active environment, and this draft before writing the server .env file.') }}
            @else
                {{ __('Draft is stored encrypted in Dply. For Functions-backed sites, keep environment values here and include them in your packaged runtime configuration instead of pushing a machine .env file.') }}
            @endif
        </p>
    </div>

    <textarea wire:model="env_file_content" rows="8" class="w-full rounded-md border-slate-300 shadow-sm font-mono text-xs" placeholder="APP_NAME=..."></textarea>
    <div class="flex flex-wrap gap-3">
        <button type="button" wire:click="saveEnvDraft" class="rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40">{{ __('Save draft in Dply') }}</button>
        @if ($supportsEnvPush)
            <button type="button" wire:click="pushEnvToServer" wire:loading.attr="disabled" class="inline-flex items-center justify-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50">
                <span wire:loading.remove wire:target="pushEnvToServer">{{ __('Push .env to server') }}</span>
                <span wire:loading wire:target="pushEnvToServer">{{ __('Pushing...') }}</span>
            </button>
        @endif
    </div>
</section>

<section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-slate-900">{{ __('Shared inventory preview') }}</h2>
        <p class="mt-1 text-sm text-slate-600">{{ __('This is the merged inventory from the deployment contract for the selected environment group. Secrets stay redacted here and on the General foundation summary.') }}</p>
    </div>

    <div class="grid gap-4 xl:grid-cols-2">
        <div class="space-y-2">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Secrets') }}</p>
            @forelse ($secretEntries as $entry)
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-3">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-mono text-sm font-medium text-slate-900">{{ $entry['key'] }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ str($entry['scope'] ?? 'site')->headline() }} · {{ str_replace('_', ' ', (string) ($entry['source'] ?? 'managed')) }}</p>
                        </div>
                        <span class="rounded-full bg-red-50 px-2.5 py-1 text-[11px] font-semibold text-red-700">{{ __('Redacted') }}</span>
                    </div>
                </div>
            @empty
                <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-3 py-3 text-sm text-slate-600">
                    {{ __('No shared secrets are inventoried yet.') }}
                </div>
            @endforelse
        </div>
        <div class="space-y-2">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Config values') }}</p>
            @forelse ($configEntries as $entry)
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-3">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-mono text-sm font-medium text-slate-900">{{ $entry['key'] }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ str($entry['scope'] ?? 'site')->headline() }} · {{ str_replace('_', ' ', (string) ($entry['source'] ?? 'managed')) }}</p>
                        </div>
                        <p class="max-w-[16rem] break-all text-right font-mono text-xs text-slate-700">{{ \Illuminate\Support\Str::limit((string) ($entry['value'] ?? ''), 80) }}</p>
                    </div>
                </div>
            @empty
                <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-3 py-3 text-sm text-slate-600">
                    {{ __('No non-secret config values are inventoried yet.') }}
                </div>
            @endforelse
        </div>
    </div>
</section>

<section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Environment variables') }}</h2>
        <p class="mt-1 text-sm text-brand-moss">{{ __('Merged with project-level variables and the raw .env draft below for the selected environment. Values are encrypted in Dply.') }}</p>
    </div>

    @if ($site->workspace && $site->workspace->variables->isNotEmpty())
        <div class="rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950">
            <p class="font-medium">{{ __('Inherited project variables') }}</p>
            <p class="mt-1">{{ __('These values are merged into the final .env for this site. Keep shared values on the project, then add a site variable only when this site needs an override.') }}</p>
        </div>
    @endif

    @if ($site->environmentVariables->isNotEmpty())
        <ul class="divide-y divide-brand-ink/10 text-sm">
            @foreach ($site->environmentVariables as $variable)
                <li class="flex justify-between gap-3 py-3">
                    <span><span class="font-mono">{{ $variable->env_key }}</span> <span class="text-brand-moss">({{ $variable->environment }})</span> = <span class="text-brand-moss">••••</span></span>
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

<section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Environment (.env)') }}</h2>
        <p class="mt-1 text-sm text-brand-moss">
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

<div>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <nav class="text-sm text-brand-moss mb-6" aria-label="Breadcrumb">
            <ol class="flex flex-wrap items-center gap-2">
                <li><a href="{{ route('dashboard') }}" class="hover:text-brand-ink transition-colors" wire:navigate>{{ __('Dashboard') }}</a></li>
                <li class="text-brand-mist" aria-hidden="true">/</li>
                <li><a href="{{ route('scripts.index') }}" class="hover:text-brand-ink transition-colors" wire:navigate>{{ __('Scripts') }}</a></li>
                <li class="text-brand-mist" aria-hidden="true">/</li>
                <li class="text-brand-ink font-medium">{{ __('Create') }}</li>
            </ol>
        </nav>

        <h1 class="text-2xl font-semibold text-brand-ink mb-2">{{ __('Create script') }}</h1>
        <p class="text-sm text-brand-moss mb-8 max-w-2xl leading-relaxed">
            {{ __('Use non-interactive flags (for example -y) so the script does not hang waiting for input.') }}
        </p>

        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm p-6 space-y-6">
            <div>
                <x-input-label for="script_name" :value="__('Label')" />
                <x-text-input id="script_name" wire:model="name" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. Install Redis extension') }}" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="script_run_as" :value="__('Run as user (optional)')" />
                <x-text-input id="script_run_as" wire:model="run_as_user" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="{{ __('Leave empty to use the server SSH user') }}" autocomplete="off" />
                <p class="mt-1 text-xs text-brand-moss">{{ __('If set, Dply runs: sudo -u user bash script.sh (requires passwordless sudo on the server).') }}</p>
                <x-input-error :messages="$errors->get('run_as_user')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="script_content" :value="__('Content')" />
                <textarea id="script_content" wire:model="content" rows="16" class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage" spellcheck="false"></textarea>
                <x-input-error :messages="$errors->get('content')" class="mt-2" />
            </div>
            <div class="flex flex-wrap justify-end gap-3">
                <a href="{{ route('scripts.index') }}" wire:navigate class="inline-flex items-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink hover:bg-brand-sand/40">{{ __('Cancel') }}</a>
                <x-primary-button type="button" wire:click="save">{{ __('Save') }}</x-primary-button>
            </div>
        </div>
    </div>
</div>

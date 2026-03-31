<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <nav class="text-sm text-brand-moss mb-6" aria-label="Breadcrumb">
            <ol class="flex flex-wrap items-center gap-2">
                <li><a href="{{ route('dashboard') }}" class="hover:text-brand-ink transition-colors" wire:navigate>{{ __('Dashboard') }}</a></li>
                <li class="text-brand-mist" aria-hidden="true">/</li>
                <li><a href="{{ route('scripts.index') }}" class="hover:text-brand-ink transition-colors" wire:navigate>{{ __('Scripts') }}</a></li>
                <li class="text-brand-mist" aria-hidden="true">/</li>
                <li class="text-brand-ink font-medium">{{ __('Marketplace') }}</li>
            </ol>
        </nav>

        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between mb-8">
            <div>
                <h1 class="text-2xl font-semibold text-brand-ink">{{ __('Script marketplace') }}</h1>
                <p class="mt-2 text-sm text-brand-moss max-w-2xl leading-relaxed">
                    {{ __('Clone a preset into your organization, then open it from Scripts to edit and run.') }}
                </p>
            </div>
            <a href="{{ route('scripts.index') }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 shrink-0">
                {{ __('Back to scripts') }}
            </a>
        </div>

        @error('marketplace')
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">{{ $message }}</div>
        @enderror

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($presets as $preset)
                <div class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm flex flex-col">
                    <h2 class="font-semibold text-brand-ink">{{ $preset['name'] }}</h2>
                    @if (! empty($preset['run_as_user']))
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Run as:') }} <code class="text-brand-ink">{{ $preset['run_as_user'] }}</code></p>
                    @endif
                    <div class="mt-4 flex-grow"></div>
                    <button type="button" wire:click="clonePreset('{{ $preset['key'] }}')" wire:loading.attr="disabled" wire:target="clonePreset('{{ $preset['key'] }}')" class="mt-4 w-full inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream hover:bg-brand-forest disabled:opacity-50">
                        <span wire:loading.remove wire:target="clonePreset('{{ $preset['key'] }}')">{{ __('Add to organization') }}</span>
                        <span wire:loading wire:target="clonePreset('{{ $preset['key'] }}')" class="inline-flex items-center gap-2">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Adding…') }}
                        </span>
                    </button>
                </div>
            @endforeach
        </div>
    </div>
</div>

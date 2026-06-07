<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <nav class="text-sm text-brand-moss mb-6" aria-label="Breadcrumb">
            <ol class="flex flex-wrap items-center gap-2">
                <li><a href="{{ route('dashboard') }}" class="hover:text-brand-ink transition-colors" wire:navigate>{{ __('Dashboard') }}</a></li>
                <li class="text-brand-mist" aria-hidden="true">/</li>
                <li><a href="{{ route('scripts.index') }}" class="hover:text-brand-ink transition-colors" wire:navigate>{{ __('Scripts') }}</a></li>
                <li class="text-brand-mist" aria-hidden="true">/</li>
                <li class="text-brand-ink font-medium">{{ __('Script presets') }}</li>
            </ol>
        </nav>

        <x-page-header
            :title="__('Script presets')"
            :description="__('Clone a reusable starter into your organization, then open it from Scripts to edit and run across servers. If a command belongs to only one machine, copy it from Scripts into that server’s Saved commands page.')"
            doc-route="docs.index"
            flush
        >
            <x-slot name="actions">
                <a href="{{ route('scripts.index') }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                    {{ __('Back to scripts') }}
                </a>
            </x-slot>
        </x-page-header>

        @error('marketplace')
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">{{ $message }}</div>
        @enderror

        @if ($webserverFilter !== '')
            <div class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/25 px-4 py-3">
                <p class="text-sm text-brand-moss">
                    {!! __('Showing scripts for <span class="font-semibold text-brand-ink">:engine</span> — :count match.', ['engine' => e(ucfirst($webserverFilter)), 'count' => count($presets)]) !!}
                </p>
                <button type="button" wire:click="$set('webserver', '')" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                    <x-heroicon-o-x-mark class="h-4 w-4" aria-hidden="true" />
                    {{ __('Show all scripts') }}
                </button>
            </div>
        @endif

        @if (count($presets) === 0)
            <div class="rounded-2xl border border-dashed border-brand-ink/15 bg-brand-sand/20 px-6 py-12 text-center">
                <p class="text-sm font-medium text-brand-ink">{{ __('No presets match this filter.') }}</p>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Clear the filter to browse the full library of script presets.') }}</p>
            </div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($presets as $preset)
                <div class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm flex flex-col">
                    <h2 class="font-semibold text-brand-ink">{{ $preset['name'] }}</h2>
                    @if (! empty($preset['run_as_user']))
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Run as:') }} <code class="text-brand-ink">{{ $preset['run_as_user'] }}</code></p>
                    @endif
                    <div class="mt-4 flex-grow"></div>
                    <button type="button" wire:click="clonePreset('{{ $preset['key'] }}')" wire:loading.attr="disabled" wire:target="clonePreset" class="mt-4 w-full inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream hover:bg-brand-forest disabled:opacity-50">
                        <span wire:loading.remove wire:target="clonePreset">{{ __('Add to organization') }}</span>
                        <span wire:loading wire:target="clonePreset" class="inline-flex items-center gap-2">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Adding…') }}
                        </span>
                    </button>
                </div>
            @endforeach
        </div>
    </div>
</div>

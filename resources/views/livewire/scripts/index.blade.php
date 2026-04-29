<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <nav class="text-sm text-brand-moss mb-6" aria-label="Breadcrumb">
            <ol class="flex flex-wrap items-center gap-2">
                <li><a href="{{ route('dashboard') }}" class="hover:text-brand-ink transition-colors" wire:navigate>{{ __('Dashboard') }}</a></li>
                <li class="text-brand-mist" aria-hidden="true">/</li>
                <li class="text-brand-ink font-medium">{{ __('Scripts') }}</li>
            </ol>
        </nav>

        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between mb-8">
            <div>
                <h1 class="text-2xl font-semibold text-brand-ink">{{ __('Scripts') }}</h1>
                <p class="mt-2 text-sm text-brand-moss max-w-2xl leading-relaxed">
                    {{ __('Keep reusable organization-wide automation here. Start from script presets, edit them anytime, and copy a script into a server only when it should become a server-local saved command.') }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2 shrink-0">
                <a href="{{ route('scripts.marketplace') }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                    {{ __('Script presets') }}
                </a>
                @can('create', App\Models\Script::class)
                    <a href="{{ route('scripts.create') }}" wire:navigate class="inline-flex items-center justify-center rounded-xl bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-sm hover:bg-brand-forest">
                        {{ __('Create script') }}
                    </a>
                @endcan
            </div>
        </div>

        @if (session('success'))
            <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900" role="status">{{ session('success') }}</div>
        @endif

        <div class="dply-card overflow-hidden">
            <div class="flex flex-col gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="w-full sm:max-w-md">
                    <label for="scripts_search" class="sr-only">{{ __('Search') }}</label>
                    <x-text-input id="scripts_search" type="search" wire:model.live.debounce.300ms="search" class="block w-full" placeholder="{{ __('Search…') }}" autocomplete="off" />
                </div>
                <button type="button" wire:click="$set('search', '')" class="text-sm font-medium text-brand-sage hover:text-brand-ink self-start sm:self-center">
                    {{ __('Reset filters') }}
                </button>
            </div>

            @if ($scripts->isEmpty())
                <div class="px-6 py-14 text-center text-sm text-brand-moss">
                    {{ __('No scripts yet. Create one or browse the marketplace.') }}
                </div>
            @else
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($scripts as $script)
                        <li wire:key="script-{{ $script->id }}">
                            <a href="{{ route('scripts.edit', $script) }}" wire:navigate class="flex items-center justify-between gap-4 px-4 py-4 hover:bg-brand-sand/20 sm:px-6">
                                <span class="font-medium text-brand-ink">{{ $script->displayName() }}</span>
                                <span class="text-xs text-brand-mist shrink-0">{{ $script->updated_at->diffForHumans() }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
                <div class="border-t border-brand-ink/10 px-4 py-3">
                    {{ $scripts->links() }}
                </div>
            @endif
        </div>
    </div>
</div>

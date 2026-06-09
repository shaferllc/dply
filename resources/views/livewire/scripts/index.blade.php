<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <nav class="text-sm text-brand-moss mb-6" aria-label="Breadcrumb">
            <ol class="flex flex-wrap items-center gap-2">
                <li><a href="{{ route('dashboard') }}" class="hover:text-brand-ink transition-colors" wire:navigate>{{ __('Dashboard') }}</a></li>
                <li class="text-brand-mist" aria-hidden="true">/</li>
                <li class="text-brand-ink font-medium">{{ __('Scripts') }}</li>
            </ol>
        </nav>

        <x-page-header
            :title="__('Scripts')"
            :description="__('Keep reusable organization-wide automation here. Start from script presets, edit them anytime, and copy a script into a server only when it should become a server-local saved command.')"
            doc-route="docs.index"
            flush
        >
            <x-slot name="actions">
                <a href="{{ route('scripts.marketplace') }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                    {{ __('Script presets') }}
                </a>
                @can('create', App\Models\Script::class)
                    <a href="{{ route('scripts.create') }}" wire:navigate class="inline-flex items-center justify-center rounded-xl bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-sm hover:bg-brand-forest">
                        {{ __('Create script') }}
                    </a>
                @endcan
            </x-slot>
        </x-page-header>

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
                        <li wire:key="script-{{ $script->id }}" class="flex flex-wrap items-center justify-between gap-4 px-4 py-4 sm:px-6">
                            <a href="{{ route('scripts.edit', $script) }}" wire:navigate class="min-w-0 flex-1 font-medium text-brand-ink hover:text-brand-sage">
                                {{ $script->displayName() }}
                            </a>
                            <div class="flex shrink-0 items-center gap-3">
                                <span class="text-xs text-brand-mist">{{ $script->updated_at->diffForHumans() }}</span>
                                @if ($vmServers->isNotEmpty())
                                    <button
                                        type="button"
                                        wire:click="openApplyModal('{{ $script->id }}')"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                                    >
                                        <x-heroicon-o-server-stack class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                        {{ __('Apply to server') }}
                                    </button>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
                <div class="border-t border-brand-ink/10 px-4 py-3">
                    {{ $scripts->links() }}
                </div>
            @endif
        </div>

        @if ($vmServers->isNotEmpty())
            <x-modal name="apply-script-to-server" maxWidth="lg" overlayClass="bg-brand-ink/40">
                <div class="relative border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                    <div class="flex items-start gap-3 pr-10">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Saved commands') }}</p>
                            <h2 class="mt-0.5 text-xl font-semibold text-brand-ink">{{ __('Apply script to a server') }}</h2>
                            <p class="mt-2 text-sm leading-relaxed text-brand-moss">
                                {{ __('Copies this organization script into the server Run workspace as a saved command. Existing commands with the same name are updated.') }}
                            </p>
                        </div>
                    </div>
                    <button type="button" wire:click="closeApplyModal" class="absolute right-4 top-4 rounded-lg p-1.5 text-brand-moss transition hover:bg-brand-sand/50 hover:text-brand-ink" aria-label="{{ __('Close') }}">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>
                <div class="space-y-4 px-6 py-5 sm:px-7">
                    <div>
                        <x-input-label for="apply_server_id" :value="__('Server')" />
                        <x-select id="apply_server_id" wire:model="applyServerId" class="mt-1 block w-full">
                            <option value="">{{ __('Choose a VM server…') }}</option>
                            @foreach ($vmServers as $vmServer)
                                <option value="{{ $vmServer->id }}">{{ $vmServer->name }}</option>
                            @endforeach
                        </x-select>
                        <x-input-error :messages="$errors->get('applyServerId')" class="mt-2" />
                    </div>
                </div>
                <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 bg-brand-sand/20 px-6 py-4 sm:px-7">
                    <button type="button" wire:click="closeApplyModal" class="inline-flex items-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                        {{ __('Cancel') }}
                    </button>
                    <button
                        type="button"
                        wire:click="confirmApplyToServer"
                        wire:loading.attr="disabled"
                        wire:target="confirmApplyToServer"
                        @disabled($applyServerId === '')
                        class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <span wire:loading.remove wire:target="confirmApplyToServer">{{ __('Apply to server') }}</span>
                        <span wire:loading wire:target="confirmApplyToServer" class="inline-flex items-center gap-2">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Applying…') }}
                        </span>
                    </button>
                </div>
            </x-modal>
        @endif
    </div>
</div>

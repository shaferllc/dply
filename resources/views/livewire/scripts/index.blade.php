@php
    $scriptsTotal = (int) ($scriptsTotal ?? 0);
    $hasScriptsInScope = $scriptsTotal > 0;
    $searchActive = trim($search) !== '';
    $canCreateScript = auth()->user()?->can('create', App\Models\Script::class) ?? false;
    $showShellActions = $hasScriptsInScope;
@endphp

<div class="contents">
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <x-breadcrumb-trail :items="array_values(array_filter([
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            feature('surface.marketplace') ? ['label' => __('Marketplace'), 'href' => route('marketplace.index'), 'icon' => 'rectangle-group'] : null,
            ['label' => __('Scripts'), 'icon' => 'code-bracket'],
        ]))" />

        <x-profile-shell
            :title="__('Scripts')"
            :description="__('Keep reusable organization-wide automation here. Start from script presets, edit them anytime, and copy a script into a server only when it should become a server-local saved command.')"
            icon="heroicon-o-code-bracket"
        >
            @if ($showShellActions)
                <x-slot:actions>
                    <a
                        href="{{ route('marketplace.index', ['category' => 'scripts']) }}"
                        wire:navigate
                        class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-rectangle-stack class="h-4 w-4" aria-hidden="true" />
                        {{ __('Browse marketplace') }}
                    </a>
                    @if ($canCreateScript)
                        <a
                            href="{{ route('scripts.create') }}"
                            wire:navigate
                            class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                        >
                            <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Create script') }}
                        </a>
                    @endif
                </x-slot:actions>
            @endif

            @if ($hasScriptsInScope)
                <x-slot:stats>
                    <dl class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                            <dt class="flex items-center gap-1.5 text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                                <x-heroicon-o-code-bracket class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                <span class="truncate">{{ __('Scripts') }}</span>
                            </dt>
                            <dd class="mt-0.5 font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ $scriptsTotal }}</dd>
                        </div>
                        <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                            <dt class="flex items-center gap-1.5 text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                                <x-heroicon-o-server-stack class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                <span class="truncate">{{ __('VM servers') }}</span>
                            </dt>
                            <dd class="mt-0.5 font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ $vmServers->count() }}</dd>
                        </div>
                    </dl>
                </x-slot:stats>
            @endif

            @if (session('success'))
                <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                    <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900" role="status">{{ session('success') }}</div>
                </div>
            @endif

            @unless ($hasScriptsInScope)
                <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6" aria-labelledby="scripts-empty-heading">
                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-code-bracket class="h-6 w-6" aria-hidden="true" />
                    </span>
                    <h2 id="scripts-empty-heading" class="mt-4 text-sm font-semibold text-brand-ink">{{ __('No scripts yet') }}</h2>
                    <p class="mt-1 max-w-md text-sm leading-relaxed text-brand-moss">
                        {{ __('Create one or browse the marketplace for organization-wide automation you can apply to servers.') }}
                    </p>
                    <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
                        <a
                            href="{{ route('marketplace.index', ['category' => 'scripts']) }}"
                            wire:navigate
                            class="inline-flex items-center justify-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                        >
                            <x-heroicon-o-rectangle-stack class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Browse marketplace') }}
                        </a>
                        @if ($canCreateScript)
                            <a
                                href="{{ route('scripts.create') }}"
                                wire:navigate
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                            >
                                <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Create script') }}
                            </a>
                        @endif
                    </div>
                </div>
            @else
                <div class="flex items-center gap-2 border-b border-brand-ink/10 px-3 py-3 sm:px-5">
                    <div class="min-w-0 flex-1">
                        <label for="scripts_search" class="sr-only">{{ __('Search') }}</label>
                        <x-text-input
                            id="scripts_search"
                            type="search"
                            wire:model.live.debounce.300ms="search"
                            class="mt-0 w-full"
                            placeholder="{{ __('Search…') }}"
                            autocomplete="off"
                        />
                    </div>
                    @if ($searchActive)
                        <button
                            type="button"
                            wire:click="$set('search', '')"
                            class="inline-flex shrink-0 items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-moss shadow-sm transition hover:bg-brand-sand/40 hover:text-brand-ink"
                        >
                            {{ __('Reset') }}
                        </button>
                    @endif
                </div>

                @if ($scripts->isEmpty())
                    <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-magnifying-glass class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('No scripts match your search') }}</p>
                        <p class="mt-1 max-w-md text-sm leading-relaxed text-brand-moss">
                            {{ __('Try a different name, or clear search to see every organization script.') }}
                        </p>
                        <button type="button" wire:click="$set('search', '')" class="mt-4 text-xs font-semibold text-brand-sage hover:text-brand-ink">
                            {{ __('Reset filters') }}
                        </button>
                    </div>
                @else
                    <ul>
                        @foreach ($scripts as $script)
                            <li
                                wire:key="script-{{ $script->id }}"
                                class="flex flex-wrap items-center justify-between gap-4 border-b border-brand-ink/10 px-5 py-4 transition-colors last:border-b-0 hover:bg-brand-sand/15 sm:px-6"
                            >
                                <a href="{{ route('scripts.edit', $script) }}" wire:navigate class="min-w-0 flex-1 text-sm font-semibold text-brand-ink hover:text-brand-sage">
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
                    @if ($scripts->hasPages())
                        <div class="border-t border-brand-ink/10 px-5 py-3 sm:px-6">
                            {{ $scripts->links() }}
                        </div>
                    @endif
                @endif
            @endunless
        </x-profile-shell>

        @if ($vmServers->isNotEmpty())
            <x-modal name="apply-script-to-server" maxWidth="lg" overlayClass="bg-brand-ink/40">
                <div class="relative border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                    <div class="flex items-start gap-3 pr-10">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Saved commands') }}</p>
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

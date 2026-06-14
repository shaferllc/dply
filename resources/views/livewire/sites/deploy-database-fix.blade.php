@php
    /**
     * Inline database-connection fix panel — rendered under the failed step.
     * Host: {@see \App\Livewire\Sites\DeployDatabaseFix}.
     */
    $d = $this->diagnosis;
    $canFix = $this->canFix;
    $retryReady = $this->retryReady;

    $labelCls = 'block text-xs font-semibold uppercase tracking-wide text-brand-moss mb-1';
    $inputCls = 'block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-1 focus:ring-brand-forest';

    $primary = $d->primaryAction();
@endphp

<div @if ($this->shouldPoll) wire:poll.4s @endif
     class="overflow-hidden rounded-2xl border border-amber-200 bg-amber-50/60">
    <div class="flex items-start gap-3 px-5 py-4">
        <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700 ring-1 ring-amber-600/20">
            <x-heroicon-o-circle-stack class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0 flex-1">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-700">{{ __('dply diagnosed this failure') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $d->headline }}</h3>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ $d->detail }}</p>

            @if ($canFix)
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    @foreach ($d->actions as $action)
                        @php $isPrimary = $action === $primary; @endphp
                        @switch ($action)
                            @case('repair')
                                <button type="button" wire:click="repairResource" wire:loading.attr="disabled" wire:target="repairResource"
                                    @class([
                                        'inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-semibold shadow-sm transition disabled:opacity-60',
                                        'bg-brand-ink text-brand-cream hover:bg-brand-forest' => $isPrimary,
                                        'border border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => ! $isPrimary,
                                    ])>
                                    <x-heroicon-o-wrench-screwdriver class="h-4 w-4" aria-hidden="true" />
                                    {{ __('Repair on server') }}
                                    @if ($isPrimary)<span class="rounded-full bg-brand-cream/20 px-1.5 py-0.5 text-[10px] uppercase tracking-wide">{{ __('Recommended') }}</span>@endif
                                </button>
                                @break

                            @case('attach')
                                <button type="button" wire:click="openAttachModal" wire:loading.attr="disabled"
                                    @class([
                                        'inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-semibold shadow-sm transition disabled:opacity-60',
                                        'bg-brand-ink text-brand-cream hover:bg-brand-forest' => $isPrimary,
                                        'border border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => ! $isPrimary,
                                    ])>
                                    <x-heroicon-o-plus-circle class="h-4 w-4" aria-hidden="true" />
                                    {{ __('Attach a database') }}
                                    @if ($isPrimary)<span class="rounded-full bg-brand-cream/20 px-1.5 py-0.5 text-[10px] uppercase tracking-wide">{{ __('Recommended') }}</span>@endif
                                </button>
                                @break

                            @case('inject')
                                <button type="button" wire:click="openInjectModal" wire:loading.attr="disabled"
                                    @class([
                                        'inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-semibold shadow-sm transition disabled:opacity-60',
                                        'bg-brand-ink text-brand-cream hover:bg-brand-forest' => $isPrimary,
                                        'border border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => ! $isPrimary,
                                    ])>
                                    <x-heroicon-o-variable class="h-4 w-4" aria-hidden="true" />
                                    {{ __('Inject DB_* settings') }}
                                    @if ($isPrimary)<span class="rounded-full bg-brand-cream/20 px-1.5 py-0.5 text-[10px] uppercase tracking-wide">{{ __('Recommended') }}</span>@endif
                                </button>
                                @break

                            @case('open_database')
                                <a href="{{ route('sites.database', ['server' => $server, 'site' => $site]) }}" wire:navigate
                                    @class([
                                        'inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-semibold shadow-sm transition',
                                        'bg-brand-ink text-brand-cream hover:bg-brand-forest' => $isPrimary,
                                        'border border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => ! $isPrimary,
                                    ])>
                                    <x-heroicon-o-circle-stack class="h-4 w-4" aria-hidden="true" />
                                    {{ __('Open the Database tab') }}
                                </a>
                                @break
                        @endswitch
                    @endforeach
                </div>
            @else
                <p class="mt-3 text-[11px] text-brand-mist">{{ __('You don’t have permission to change this site’s database or environment. Ask an operator on this organization to apply a fix.') }}</p>
            @endif
        </div>
    </div>

    {{-- Apply & retry — disabled until a fix has actually pushed env to the box (Q5). --}}
    @if ($canFix)
        <div class="flex flex-wrap items-center gap-3 border-t border-amber-200/70 bg-white/40 px-5 py-3">
            <button type="button" wire:click="retryDeploy" wire:loading.attr="disabled" wire:target="retryDeploy"
                @disabled(! $retryReady)
                @class([
                    'inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-semibold shadow-sm transition',
                    'bg-emerald-600 text-white hover:bg-emerald-700' => $retryReady,
                    'cursor-not-allowed bg-brand-ink/10 text-brand-mist' => ! $retryReady,
                ])>
                <x-heroicon-o-rocket-launch class="h-4 w-4" aria-hidden="true" />
                {{ $this->deployment->isResumable() ? __('Apply & retry from release') : __('Apply & re-deploy') }}
            </button>
            <p class="text-[11px] text-brand-mist">
                @if ($retryReady)
                    {{ $this->deployment->isResumable()
                        ? __('Re-runs the deploy from the release phase, reusing the build that already succeeded.')
                        : __('Runs a fresh deploy with the new database settings.') }}
                @else
                    {{ __('Apply a fix above first — this enables once the new settings reach the server.') }}
                @endif
            </p>
        </div>
    @endif

    {{-- ───────────────────────── Attach-a-database modal ───────────────────────── --}}
    <x-modal name="deploy-db-fix-attach" max-width="lg" focusable>
        <div class="p-6">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Attach a database') }}</h3>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('dply provisions the database on this server and writes the connection into your environment, then pushes it live.') }}</p>
                </div>
                <button type="button" x-on:click="$dispatch('close-modal', 'deploy-db-fix-attach')" class="shrink-0 rounded-lg p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink">
                    <x-heroicon-o-x-mark class="h-5 w-5" />
                </button>
            </div>

            @if (! $enginesLoaded)
                <p class="mt-6 text-sm text-brand-moss">{{ __('Checking installed database engines…') }}</p>
            @elseif ($installedEngines === [])
                {{-- No engine installed (Q7): be honest, route to the server-level engine install. --}}
                <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50/70 p-4">
                    <p class="text-sm font-semibold text-brand-ink">{{ __('No database engine is installed on this server') }}</p>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Install MySQL, MariaDB, or PostgreSQL first, then come back here to attach a database.') }}</p>
                    <a href="{{ route('servers.databases', ['server' => $server]) }}" wire:navigate
                        class="mt-3 inline-flex items-center gap-2 rounded-lg bg-brand-ink px-3.5 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest">
                        <x-heroicon-o-server-stack class="h-4 w-4" aria-hidden="true" />
                        {{ __('Install a database engine') }}
                    </a>
                </div>
                <div class="mt-5 flex justify-end">
                    <x-secondary-button size="sm" type="button" x-on:click="$dispatch('close-modal', 'deploy-db-fix-attach')">{{ __('Close') }}</x-secondary-button>
                </div>
            @else
                <form wire:submit="createDatabase" class="mt-4 space-y-3">
                    <div>
                        <label class="{{ $labelCls }}" for="fix_new_db_name">{{ __('Database name') }}</label>
                        <input id="fix_new_db_name" type="text" wire:model="new_db_name" class="{{ $inputCls }} font-mono" />
                        <x-input-error :messages="$errors->get('new_db_name')" class="mt-1" />
                    </div>
                    <div>
                        <label class="{{ $labelCls }}" for="fix_new_db_engine">{{ __('Engine') }}</label>
                        <select id="fix_new_db_engine" wire:model="new_db_engine" class="{{ $inputCls }}">
                            @foreach ($installedEngines as $engine)
                                <option value="{{ $engine }}">{{ \App\Support\Servers\DatabaseWorkspaceEngines::label($engine) }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('new_db_engine')" class="mt-1" />
                    </div>
                    <p class="rounded-lg bg-brand-sand/30 px-3 py-2 text-[11px] text-brand-moss">
                        {{ __('A user and password are generated automatically and written to your .env as DB_*. The .env is pushed to the server so the retry can connect.') }}
                    </p>

                    <div class="flex justify-end gap-2 pt-1">
                        <x-secondary-button size="sm" type="button" x-on:click="$dispatch('close-modal', 'deploy-db-fix-attach')">{{ __('Cancel') }}</x-secondary-button>
                        <x-primary-button size="sm" type="submit" wire:loading.attr="disabled" wire:target="createDatabase">
                            <span wire:loading.remove wire:target="createDatabase">{{ __('Attach database') }}</span>
                            <span wire:loading wire:target="createDatabase">{{ __('Queuing…') }}</span>
                        </x-primary-button>
                    </div>
                </form>
            @endif
        </div>
    </x-modal>

    {{-- ───────────────────────── Inject-DB_* modal ───────────────────────── --}}
    <x-modal name="deploy-db-fix-inject" max-width="lg" focusable>
        <form wire:submit="saveInject" class="p-6">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Inject database settings') }}</h3>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Edit the DB_* variables below. They’re merged into your environment and pushed to the server.') }}</p>
                </div>
                <button type="button" x-on:click="$dispatch('close-modal', 'deploy-db-fix-inject')" class="shrink-0 rounded-lg p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink">
                    <x-heroicon-o-x-mark class="h-5 w-5" />
                </button>
            </div>

            <div class="mt-4">
                <label class="{{ $labelCls }}" for="fix_inject_env">{{ __('Environment variables') }}</label>
                <textarea id="fix_inject_env" wire:model="inject_env" rows="7"
                    class="{{ $inputCls }} font-mono text-xs leading-relaxed" spellcheck="false"></textarea>
                <p class="mt-1 text-[11px] text-brand-mist">{{ __('One KEY=value per line. Existing variables with the same key are overwritten.') }}</p>
                <x-input-error :messages="$errors->get('inject_env')" class="mt-1" />
            </div>

            <div class="mt-5 flex justify-end gap-2">
                <x-secondary-button size="sm" type="button" x-on:click="$dispatch('close-modal', 'deploy-db-fix-inject')">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button size="sm" type="submit" wire:loading.attr="disabled" wire:target="saveInject">
                    <span wire:loading.remove wire:target="saveInject">{{ __('Inject & push') }}</span>
                    <span wire:loading wire:target="saveInject">{{ __('Queuing…') }}</span>
                </x-primary-button>
            </div>
        </form>
    </x-modal>
</div>

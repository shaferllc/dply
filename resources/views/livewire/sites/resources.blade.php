@php
    $card = 'rounded-xl border border-brand-ink/10 bg-white p-5 shadow-sm';
    $labelCls = 'block text-xs font-semibold uppercase tracking-wide text-brand-moss mb-1';
    $inputCls = 'block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-1 focus:ring-brand-forest';
    $statusColors = [
        'active' => 'bg-emerald-100 text-emerald-800',
        'provisioning' => 'bg-amber-100 text-amber-900',
        'failed' => 'bg-rose-100 text-rose-800',
        'deleting' => 'bg-slate-200 text-slate-700',
    ];
@endphp

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <nav class="text-sm text-brand-moss mb-6" aria-label="{{ __('Breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-2">
            <li><a href="{{ route('dashboard') }}" wire:navigate class="hover:text-brand-ink transition-colors">{{ __('Dashboard') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('sites.show', [$server, $site]) }}" wire:navigate class="hover:text-brand-ink transition-colors truncate max-w-[12rem]" title="{{ $site->name }}">{{ $site->name }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li class="text-brand-ink font-medium">{{ __('Resources') }}</li>
        </ol>
    </nav>

    <div class="mb-6 flex items-center justify-between gap-3 border-b border-brand-ink/10 pb-6">
        <div>
            <h1 class="text-2xl font-semibold text-brand-ink">{{ __('Resources') }}</h1>
            <p class="mt-1 text-sm text-brand-moss">
                {{ $isContainer
                    ? __('Every backing service attached to this app. Attach more in one click; detach in place.')
                    : __('Background workers that keep this site\'s queue and Horizon running — on this server and any worker server on the same network.') }}
            </p>
        </div>
        @if ($isContainer)
            <x-primary-button size="sm" type="button" wire:click="openAttach('attach')">
                + {{ __('Attach resource') }}
            </x-primary-button>
        @else
            <a href="{{ route('sites.daemons', [$server, $site]) }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors disabled:cursor-not-allowed disabled:opacity-50">
                {{ __('Manage workers') }}
            </a>
        @endif
    </div>

@if (! $isContainer)
    {{-- VM workers roll-up — read-only; management lives on the Workers page. --}}
    <div class="{{ $card }}">
        <div class="flex items-baseline justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-brand-moss">{{ __('Workers') }}</h2>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Queue / Horizon / scheduler processes draining this site\'s work. Off-box rows run on a worker server that shares this site\'s private network.') }}</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('sites.daemons', [$server, $site]) }}?preset=laravel-queue&open=worker" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/40 transition-colors disabled:cursor-not-allowed disabled:opacity-50">+ {{ __('Queue worker') }}</a>
                <a href="{{ route('sites.daemons', [$server, $site]) }}?preset=laravel-horizon&open=worker" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/40 transition-colors disabled:cursor-not-allowed disabled:opacity-50">+ {{ __('Horizon') }}</a>
            </div>
        </div>

        @if ($this->vmWorkers->isEmpty())
            <p class="mt-4 text-xs italic text-brand-moss">{{ __('No workers detected for this site — on this server or any worker server on the same network.') }}</p>
        @else
            <ul class="mt-4 divide-y divide-brand-ink/8 rounded-lg border border-brand-ink/10">
                @foreach ($this->vmWorkers as $worker)
                    <li class="px-4 py-3">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold text-sm text-brand-ink">{{ $worker['name'] }}</span>
                                    <span class="rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $worker['active'] ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700' }}">{{ $worker['active'] ? __('active') : __('inactive') }}</span>
                                    <span class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-mono text-slate-700">{{ $worker['type'] }}</span>
                                    <span class="text-[10px] text-brand-moss">{{ $worker['source'] }} · ×{{ $worker['instances'] }}</span>
                                    @if ($worker['off_box'])
                                        <span class="inline-flex items-center gap-1 rounded-full bg-sky-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-800" title="{{ __('Runs on a worker server on the same private network') }}">
                                            <x-heroicon-o-server class="h-3 w-3" aria-hidden="true" />
                                            {{ $worker['server_name'] }}
                                        </span>
                                    @endif
                                </div>
                                <div class="mt-1 font-mono text-[11px] text-brand-ink break-all">{{ $worker['command'] }}</div>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@else
    {{-- Databases --}}
    <div class="{{ $card }} mb-6">
        <div class="flex items-baseline justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-brand-moss">{{ __('Databases') }}</h2>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Managed Postgres / MySQL / Redis. Connection env vars are merged into the site\'s env file and a redeploy is queued automatically on attach.') }}</p>
            </div>
            <div class="flex gap-2">
                <x-secondary-button size="sm" type="button" wire:click="openAttach('database-existing')">+ {{ __('Attach') }}</x-secondary-button>
                <x-secondary-button size="sm" type="button" wire:click="openAttach('database-new')">+ {{ __('Create new') }}</x-secondary-button>
            </div>
        </div>

        @if ($this->attachedDatabases->isEmpty())
            <p class="mt-4 text-xs italic text-brand-moss">{{ __('No databases attached yet.') }}</p>
        @else
            <ul class="mt-4 divide-y divide-brand-ink/8 rounded-lg border border-brand-ink/10">
                @foreach ($this->attachedDatabases as $db)
                    <li class="px-4 py-3">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="font-semibold text-sm text-brand-ink">{{ $db->name }}</span>
                                    <span class="rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $statusColors[$db->status] ?? 'bg-slate-200 text-slate-700' }}">{{ $db->status }}</span>
                                    <span class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-mono text-slate-700">{{ $db->engine }}</span>
                                    <span class="text-[10px] text-brand-moss">{{ $db->size }}</span>
                                </div>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    @foreach ($db->connectionEnvKeys() as $key)
                                        <span class="rounded bg-brand-sand/30 px-1.5 py-0.5 text-[10px] font-mono text-brand-ink">{{ $key }}</span>
                                    @endforeach
                                </div>
                            </div>
                            <button type="button"
                                wire:click="detachDatabase('{{ $db->id }}')"
                                wire:confirm="{{ __('Detach :name? DB env vars will be removed and the site will redeploy.', ['name' => $db->name]) }}"
                                class="text-[11px] font-semibold text-rose-700 hover:underline">
                                {{ __('Detach') }}
                            </button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    {{-- Workers + scheduler --}}
    <div class="{{ $card }}">
        <div class="flex items-baseline justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-brand-moss">{{ __('Background processes') }}</h2>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Queue workers and the Laravel scheduler. Each becomes a long-running App Platform component built from the same source as the web service.') }}</p>
            </div>
            <div class="flex gap-2">
                <x-secondary-button size="sm" type="button" wire:click="openAttach('worker')">+ {{ __('Worker') }}</x-secondary-button>
                <x-secondary-button size="sm" type="button" wire:click="openAttach('scheduler')" @disabled($this->hasScheduler())>+ {{ __('Scheduler') }}</x-secondary-button>
            </div>
        </div>

        @if ($this->workers->isEmpty())
            <p class="mt-4 text-xs italic text-brand-moss">{{ __('No background processes yet.') }}</p>
        @else
            <ul class="mt-4 divide-y divide-brand-ink/8 rounded-lg border border-brand-ink/10">
                @foreach ($this->workers as $worker)
                    <li class="px-4 py-3">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="font-semibold text-sm text-brand-ink">{{ $worker->name }}</span>
                                    <span class="rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $statusColors[$worker->status] ?? 'bg-slate-200 text-slate-700' }}">{{ $worker->status }}</span>
                                    <span class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-mono text-slate-700">{{ $worker->type }}</span>
                                    <span class="text-[10px] text-brand-moss">{{ $worker->size }} · ×{{ $worker->effectiveInstanceCount() }}</span>
                                </div>
                                <div class="mt-1 font-mono text-[11px] text-brand-ink break-all">{{ $worker->effectiveCommand() }}</div>
                            </div>
                            <button type="button"
                                wire:click="detachWorker('{{ $worker->id }}')"
                                wire:confirm="{{ __('Remove :name?', ['name' => $worker->name]) }}"
                                class="text-[11px] font-semibold text-rose-700 hover:underline">
                                {{ __('Remove') }}
                            </button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    {{-- Attach modal --}}
    @if ($modal !== '')
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-brand-ink/40 p-4" wire:click.self="closeModal">
            <div class="w-full max-w-2xl rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-brand-ink/10 px-6 py-4">
                    <h3 class="text-lg font-semibold text-brand-ink">
                        @switch($modal)
                            @case('attach') {{ __('Attach a resource') }} @break
                            @case('database-existing') {{ __('Attach existing database') }} @break
                            @case('database-new') {{ __('Create new database') }} @break
                            @case('worker') {{ __('Add queue worker') }} @break
                            @case('scheduler') {{ __('Add scheduler') }} @break
                        @endswitch
                    </h3>
                    <button type="button" wire:click="closeModal" class="text-brand-mist hover:text-brand-ink">✕</button>
                </div>

                <div class="px-6 py-5">
                    @if ($modal === 'attach')
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <button type="button" wire:click="openAttach('database-existing')" class="rounded-lg border border-brand-ink/10 p-4 text-left hover:bg-brand-sand/30" @disabled($this->attachableDatabases->isEmpty())>
                                <div class="text-sm font-semibold text-brand-ink">{{ __('Database (attach existing)') }}</div>
                                <p class="mt-1 text-xs text-brand-moss">{{ __('Pick a managed DB already in this organization.') }}</p>
                                @if ($this->attachableDatabases->isEmpty())
                                    <p class="mt-2 text-[10px] uppercase tracking-wide text-brand-mist">{{ __('No detachable databases') }}</p>
                                @endif
                            </button>
                            <button type="button" wire:click="openAttach('database-new')" class="rounded-lg border border-brand-ink/10 p-4 text-left hover:bg-brand-sand/30">
                                <div class="text-sm font-semibold text-brand-ink">{{ __('Database (create new)') }}</div>
                                <p class="mt-1 text-xs text-brand-moss">{{ __('Provision a fresh Postgres / MySQL / Redis cluster, attach on activation.') }}</p>
                            </button>
                            <button type="button" wire:click="openAttach('worker')" class="rounded-lg border border-brand-ink/10 p-4 text-left hover:bg-brand-sand/30">
                                <div class="text-sm font-semibold text-brand-ink">{{ __('Queue worker') }}</div>
                                <p class="mt-1 text-xs text-brand-moss">{{ __('Long-running App Platform component running a custom command.') }}</p>
                            </button>
                            <button type="button" wire:click="openAttach('scheduler')" class="rounded-lg border border-brand-ink/10 p-4 text-left hover:bg-brand-sand/30" @disabled($this->hasScheduler())>
                                <div class="text-sm font-semibold text-brand-ink">{{ __('Scheduler') }}</div>
                                <p class="mt-1 text-xs text-brand-moss">{{ __('Runs `php artisan schedule:work` on a single pinned instance.') }}</p>
                                @if ($this->hasScheduler())
                                    <p class="mt-2 text-[10px] uppercase tracking-wide text-brand-mist">{{ __('Already attached') }}</p>
                                @endif
                            </button>
                        </div>

                    @elseif ($modal === 'database-existing')
                        <form wire:submit.prevent="attachExistingDatabase" class="space-y-4">
                            <div>
                                <label class="{{ $labelCls }}" for="attach_database_id">{{ __('Database') }}</label>
                                <select id="attach_database_id" wire:model="attach_database_id" class="{{ $inputCls }}" required>
                                    <option value="">{{ __('— select —') }}</option>
                                    @foreach ($this->attachableDatabases as $db)
                                        <option value="{{ $db->id }}">{{ $db->name }} · {{ $db->engine }} · {{ $db->status }}</option>
                                    @endforeach
                                </select>
                                @error('attach_database_id') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                            </div>
                            <div class="flex justify-end gap-2">
                                <x-secondary-button size="sm" type="button" wire:click="closeModal">{{ __('Cancel') }}</x-secondary-button>
                                <x-primary-button size="sm" type="submit">{{ __('Attach') }}</x-primary-button>
                            </div>
                        </form>

                    @elseif ($modal === 'database-new')
                        <form wire:submit.prevent="createNewDatabase" class="space-y-4">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="{{ $labelCls }}" for="new_database_name">{{ __('Name') }}</label>
                                    <input id="new_database_name" type="text" wire:model="new_database_name" class="{{ $inputCls }}" placeholder="acme-prod" required>
                                    @error('new_database_name') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="{{ $labelCls }}" for="new_database_engine">{{ __('Engine') }}</label>
                                    <select id="new_database_engine" wire:model="new_database_engine" class="{{ $inputCls }}">
                                        <option value="postgres">Postgres</option>
                                        <option value="mysql">MySQL</option>
                                        <option value="redis">Redis</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="{{ $labelCls }}" for="new_database_size">{{ __('Size') }}</label>
                                    <select id="new_database_size" wire:model="new_database_size" class="{{ $inputCls }}">
                                        <option value="small">small (1 vCPU / 1 GB)</option>
                                        <option value="medium">medium (1 vCPU / 2 GB)</option>
                                        <option value="large">large (2 vCPU / 4 GB)</option>
                                    </select>
                                </div>
                            </div>
                            <p class="text-xs text-brand-moss">{{ __('Provisioning takes ~5-10 minutes. DB_* env vars are merged and the site is redeployed automatically once the cluster is online.') }}</p>
                            <div class="flex justify-end gap-2">
                                <x-secondary-button size="sm" type="button" wire:click="closeModal">{{ __('Cancel') }}</x-secondary-button>
                                <x-primary-button size="sm" type="submit">{{ __('Create') }}</x-primary-button>
                            </div>
                        </form>

                    @elseif ($modal === 'worker')
                        <form wire:submit.prevent="attachWorker('worker')" class="space-y-4">
                            <div>
                                <label class="{{ $labelCls }}" for="worker_name">{{ __('Name') }}</label>
                                <input id="worker_name" type="text" wire:model="worker_name" class="{{ $inputCls }}" placeholder="queue-redis" required>
                                @error('worker_name') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="{{ $labelCls }}" for="worker_command">{{ __('Command') }}</label>
                                <input id="worker_command" type="text" wire:model="worker_command" class="{{ $inputCls }} font-mono" required>
                                @error('worker_command') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="{{ $labelCls }}" for="worker_size">{{ __('Size') }}</label>
                                    <select id="worker_size" wire:model="worker_size" class="{{ $inputCls }}">
                                        <option value="small">small</option>
                                        <option value="medium">medium</option>
                                        <option value="large">large</option>
                                        <option value="xlarge">xlarge</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="{{ $labelCls }}" for="worker_instance_count">{{ __('Instances') }}</label>
                                    <input id="worker_instance_count" type="number" min="1" max="50" wire:model="worker_instance_count" class="{{ $inputCls }}">
                                </div>
                            </div>
                            <div class="flex justify-end gap-2">
                                <x-secondary-button size="sm" type="button" wire:click="closeModal">{{ __('Cancel') }}</x-secondary-button>
                                <x-primary-button size="sm" type="submit">{{ __('Add worker') }}</x-primary-button>
                            </div>
                        </form>

                    @elseif ($modal === 'scheduler')
                        <form wire:submit.prevent="attachWorker('scheduler')" class="space-y-4">
                            <p class="text-sm text-brand-ink">{{ __('The scheduler runs `php artisan schedule:work` on a single pinned instance — App Platform has no native cron, so this is how Laravel\'s scheduled tasks fire.') }}</p>
                            <div class="flex justify-end gap-2">
                                <x-secondary-button size="sm" type="button" wire:click="closeModal">{{ __('Cancel') }}</x-secondary-button>
                                <x-primary-button size="sm" type="submit">{{ __('Add scheduler') }}</x-primary-button>
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    @endif
@endif
</div>

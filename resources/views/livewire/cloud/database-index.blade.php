<div class="mx-auto max-w-6xl px-6 py-10">
    <header class="mb-6 flex flex-wrap items-end justify-between gap-4 border-b border-slate-200 pb-4">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ __('Managed databases') }}</h1>
            <p class="mt-1 text-sm text-slate-600">{{ __('Hosted Postgres, MySQL, and Redis instances dply provisions and attaches to your Cloud apps across :org.', ['org' => $org->name]) }}</p>
        </div>
        <a href="{{ route('cloud.databases.create') }}" wire:navigate class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
            {{ __('Create database') }}
        </a>
    </header>

    <div class="mb-5 flex flex-wrap items-center gap-6">
        <nav class="flex flex-wrap gap-2 text-xs">
            @php
                $engineTabs = [
                    ['key' => 'all', 'label' => __('All engines'), 'count' => $totals['all']],
                    ['key' => 'postgres', 'label' => 'Postgres', 'count' => $totals['postgres']],
                    ['key' => 'mysql', 'label' => 'MySQL', 'count' => $totals['mysql']],
                    ['key' => 'redis', 'label' => 'Redis', 'count' => $totals['redis']],
                ];
            @endphp
            @foreach ($engineTabs as $tab)
                <button type="button" wire:click="$set('engine', '{{ $tab['key'] }}')" class="rounded-full border px-3 py-1.5 font-semibold {{ $engine === $tab['key'] ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300' }}">
                    {{ $tab['label'] }}
                    <span class="ml-1 font-mono opacity-80">{{ $tab['count'] }}</span>
                </button>
            @endforeach
        </nav>
        <nav class="flex flex-wrap gap-2 text-xs">
            @php
                $statusTabs = [
                    ['key' => 'all', 'label' => __('Any status'), 'count' => $totals['all']],
                    ['key' => \App\Models\CloudDatabase::STATUS_ACTIVE, 'label' => __('Active'), 'count' => $totals['active']],
                    ['key' => \App\Models\CloudDatabase::STATUS_PROVISIONING, 'label' => __('Provisioning'), 'count' => $totals['provisioning']],
                    ['key' => \App\Models\CloudDatabase::STATUS_FAILED, 'label' => __('Failed'), 'count' => $totals['failed']],
                ];
            @endphp
            @foreach ($statusTabs as $tab)
                <button type="button" wire:click="$set('status', '{{ $tab['key'] }}')" class="rounded-full border px-3 py-1.5 font-semibold {{ $status === $tab['key'] ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300' }}">
                    {{ $tab['label'] }}
                    <span class="ml-1 font-mono opacity-80">{{ $tab['count'] }}</span>
                </button>
            @endforeach
        </nav>
    </div>

    @if ($databases->isEmpty())
        <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-600 shadow-sm">
            <p class="font-semibold text-slate-900">{{ __('No managed databases found') }}</p>
            <p class="mt-1">{{ __('Databases you create here can be attached to any Cloud container app — dply injects the connection env vars for you.') }}</p>
        </div>
    @else
        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">
                    <tr>
                        <th class="px-4 py-3">{{ __('Name') }}</th>
                        <th class="px-4 py-3">{{ __('Engine') }}</th>
                        <th class="px-4 py-3">{{ __('Version') }}</th>
                        <th class="px-4 py-3">{{ __('Size') }}</th>
                        <th class="px-4 py-3">{{ __('Region') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3">{{ __('Attached apps') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-800">
                    @foreach ($databases as $database)
                        @php
                            $statusBadge = match ($database->status) {
                                \App\Models\CloudDatabase::STATUS_ACTIVE => 'bg-emerald-100 text-emerald-800',
                                \App\Models\CloudDatabase::STATUS_PROVISIONING => 'bg-sky-100 text-sky-800',
                                \App\Models\CloudDatabase::STATUS_FAILED => 'bg-rose-100 text-rose-800',
                                \App\Models\CloudDatabase::STATUS_DELETING => 'bg-amber-100 text-amber-800',
                                default => 'bg-slate-100 text-slate-700',
                            };
                            $statusLabel = match ($database->status) {
                                \App\Models\CloudDatabase::STATUS_ACTIVE => __('Active'),
                                \App\Models\CloudDatabase::STATUS_PROVISIONING => __('Provisioning'),
                                \App\Models\CloudDatabase::STATUS_FAILED => __('Failed'),
                                \App\Models\CloudDatabase::STATUS_DELETING => __('Deleting'),
                                default => str_replace('_', ' ', (string) $database->status),
                            };
                            $engineLabel = match ($database->engine) {
                                \App\Models\CloudDatabase::ENGINE_POSTGRES => 'Postgres',
                                \App\Models\CloudDatabase::ENGINE_MYSQL => 'MySQL',
                                \App\Models\CloudDatabase::ENGINE_REDIS => 'Redis',
                                default => ucfirst((string) $database->engine),
                            };
                        @endphp
                        <tr>
                            <td class="px-4 py-3 font-medium text-slate-900">{{ $database->name }}</td>
                            <td class="px-4 py-3 text-slate-700">{{ $engineLabel }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-500">{{ $database->version ?: '—' }}</td>
                            <td class="px-4 py-3 text-slate-700">{{ ucfirst((string) $database->size) }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-500">{{ $database->region ?: '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] {{ $statusBadge }}">{{ $statusLabel }}</span>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-500">{{ $database->sites_count }}</td>
                            <td class="px-4 py-3 text-right">
                                @if ($database->status !== \App\Models\CloudDatabase::STATUS_DELETING)
                                    <button type="button"
                                        wire:click="tearDown('{{ $database->id }}')"
                                        wire:confirm="{{ __('Tear down :name? The database cluster will be permanently deleted on the backend and detached from all apps.', ['name' => $database->name]) }}"
                                        class="text-xs font-medium text-rose-700 hover:text-rose-900">
                                        {{ __('Tear down') }}
                                    </button>
                                @else
                                    <span class="text-xs text-slate-400">{{ __('Deleting…') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

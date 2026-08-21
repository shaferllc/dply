@php
    use App\Models\CloudDatabase;
    use App\Modules\Database\Backends\DatabaseBackend;

    $statusBadge = match ($database->status) {
        CloudDatabase::STATUS_ACTIVE => 'bg-emerald-100 text-emerald-800',
        CloudDatabase::STATUS_PROVISIONING => 'bg-sky-100 text-sky-800',
        CloudDatabase::STATUS_FAILED => 'bg-rose-100 text-rose-800',
        CloudDatabase::STATUS_DELETING => 'bg-amber-100 text-amber-800',
        default => 'bg-slate-100 text-slate-700',
    };
    $statusLabel = match ($database->status) {
        CloudDatabase::STATUS_ACTIVE => __('Active'),
        CloudDatabase::STATUS_PROVISIONING => __('Provisioning'),
        CloudDatabase::STATUS_FAILED => __('Failed'),
        CloudDatabase::STATUS_DELETING => __('Deleting'),
        default => str_replace('_', ' ', (string) $database->status),
    };
    $engineLabel = match ($database->engine) {
        CloudDatabase::ENGINE_POSTGRES => 'Postgres',
        CloudDatabase::ENGINE_MYSQL => 'MySQL',
        CloudDatabase::ENGINE_REDIS => 'Redis / Valkey',
        default => ucfirst((string) $database->engine),
    };

    $tabs = [
        ['key' => 'overview', 'label' => __('Overview'), 'available' => true],
        ['key' => 'sites', 'label' => __('Apps'), 'available' => true],
        ['key' => 'users', 'label' => __('Users'), 'available' => $capabilities[DatabaseBackend::CAP_USERS]],
        ['key' => 'network', 'label' => __('Network'), 'available' => $canManageNetwork],
        ['key' => 'scale', 'label' => __('Scale'), 'available' => $capabilities[DatabaseBackend::CAP_RESIZE]],
        ['key' => 'metrics', 'label' => __('Metrics'), 'available' => $capabilities[DatabaseBackend::CAP_METRICS]],
        ['key' => 'backups', 'label' => __('Backups'), 'available' => $capabilities[DatabaseBackend::CAP_BACKUPS]],
        ['key' => 'danger', 'label' => __('Danger'), 'available' => true],
    ];
@endphp

<div class="mx-auto max-w-6xl px-6 py-10">
    <x-breadcrumb-trail :items="$breadcrumbs" />

    <header class="mb-6 flex flex-wrap items-end justify-between gap-4 border-b border-slate-200 pb-4">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-semibold text-slate-900">{{ $database->name }}</h1>
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-2xs font-semibold uppercase tracking-[0.14em] {{ $statusBadge }}">{{ $statusLabel }}</span>
            </div>
            <p class="mt-1 text-sm text-slate-600">
                {{ $engineLabel }}@if ($database->version) {{ ' '.$database->version }}@endif
                · {{ \App\Support\Servers\ManagedDatabaseSizeCatalog::label($database->backendSizeSlug()) }}
                @if ($database->region) · <span class="font-mono text-xs">{{ $database->region }}</span> @endif
            </p>
        </div>
        <a href="{{ route('cloud.databases.index') }}" wire:navigate class="text-sm font-medium text-slate-600 hover:text-slate-900">
            {{ __('← All databases') }}
        </a>
    </header>

    @if (($database->meta['error'] ?? null))
        <div class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
            <p class="font-semibold">{{ __('Last operation failed') }}</p>
            <p class="mt-1">{{ $database->meta['error'] }}</p>
        </div>
    @endif

    <nav class="mb-6 flex flex-wrap gap-2 text-xs">
        @foreach ($tabs as $item)
            <button type="button" wire:click="$set('tab', '{{ $item['key'] }}')"
                class="rounded-full border px-3 py-1.5 font-semibold {{ $tab === $item['key'] ? 'border-slate-900 bg-slate-900 text-white' : ($item['available'] ? 'border-slate-200 bg-white text-slate-700 hover:border-slate-300' : 'border-slate-200 bg-white text-slate-400 hover:border-slate-300') }}">
                {{ $item['label'] }}
            </button>
        @endforeach
    </nav>

    @if ($tab === 'overview')
        @include('livewire.cloud.partials.database.overview')
    @elseif ($tab === 'sites')
        @include('livewire.cloud.partials.database.sites')
    @elseif ($tab === 'users')
        @include('livewire.cloud.partials.database.users')
    @elseif ($tab === 'network')
        @include('livewire.cloud.partials.database.network')
    @elseif ($tab === 'scale')
        @include('livewire.cloud.partials.database.scale')
    @elseif ($tab === 'metrics')
        @include('livewire.cloud.partials.database.metrics')
    @elseif ($tab === 'backups')
        @include('livewire.cloud.partials.database.backups')
    @elseif ($tab === 'danger')
        @include('livewire.cloud.partials.database.danger')
    @endif
</div>

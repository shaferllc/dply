@php
    $connection = $database->getAttribute('connection');
    $connection = is_array($connection) ? $connection : [];
    $envVars = $database->connectionEnvVars();
    $restoredFrom = $database->meta['restored_from'] ?? null;
    $resizingTo = $database->meta['resizing_to'] ?? null;
@endphp

<div class="space-y-6">
    @if ($resizingTo)
        <div class="rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900">
            {{ __('Moving to :size. The cluster is unavailable until it settles.', ['size' => \App\Support\Servers\ManagedDatabaseSizeCatalog::label((string) $resizingTo)]) }}
        </div>
    @endif

    @if (is_array($restoredFrom))
        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
            {{ __('Restored from :name, backup taken :at.', [
                'name' => (string) ($restoredFrom['name'] ?? __('another database')),
                'at' => (string) ($restoredFrom['backup_created_at'] ?? '—'),
            ]) }}
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['label' => __('Engine'), 'value' => $engineLabel],
            ['label' => __('Version'), 'value' => $database->version ?: '—'],
            ['label' => __('Plan'), 'value' => \App\Support\Servers\ManagedDatabaseSizeCatalog::label($database->backendSizeSlug())],
            ['label' => __('Region'), 'value' => $database->region ?: '—'],
        ] as $fact)
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ $fact['label'] }}</p>
                <p class="mt-1 text-sm font-medium text-slate-900">{{ $fact['value'] }}</p>
            </div>
        @endforeach
    </div>

    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="text-sm font-semibold text-slate-900">{{ __('Connection') }}</h2>
            <p class="mt-1 text-xs text-slate-600">{{ __('The admin login dply provisioned. Attached apps receive these as environment variables at deploy time.') }}</p>
        </div>

        @if ($connection === [])
            <p class="px-5 py-8 text-center text-sm text-slate-600">
                {{ __('No connection details yet — the cluster is still coming online.') }}
            </p>
        @else
            <dl class="divide-y divide-slate-100 text-sm">
                @foreach ([
                    ['label' => __('Host'), 'value' => (string) ($connection['host'] ?? '')],
                    ['label' => __('Port'), 'value' => (string) ($connection['port'] ?? '')],
                    ['label' => __('Database'), 'value' => (string) ($connection['database'] ?? '')],
                    ['label' => __('Username'), 'value' => $adminUsername],
                ] as $row)
                    <div class="flex items-center justify-between gap-4 px-5 py-3">
                        <dt class="text-slate-600">{{ $row['label'] }}</dt>
                        <dd class="font-mono text-xs text-slate-900">{{ $row['value'] !== '' ? $row['value'] : '—' }}</dd>
                    </div>
                @endforeach
                <div class="flex items-center justify-between gap-4 px-5 py-3">
                    <dt class="text-slate-600">{{ __('Password') }}</dt>
                    <dd class="flex items-center gap-3">
                        <span class="font-mono text-xs text-slate-900">
                            {{ $revealPassword ? (string) ($connection['password'] ?? '') : str_repeat('•', 16) }}
                        </span>
                        <button type="button" wire:click="$toggle('revealPassword')" class="text-xs font-medium text-slate-600 hover:text-slate-900">
                            {{ $revealPassword ? __('Hide') : __('Reveal') }}
                        </button>
                    </dd>
                </div>
            </dl>
        @endif
    </div>

    @if ($envVars !== [])
        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-sm font-semibold text-slate-900">{{ __('Injected environment variables') }}</h2>
                <p class="mt-1 text-xs text-slate-600">{{ __('Exactly what an attached app receives. Values are masked here; the app gets the real ones.') }}</p>
            </div>
            <dl class="divide-y divide-slate-100 text-sm">
                @foreach ($envVars as $key => $value)
                    <div class="flex items-center justify-between gap-4 px-5 py-2.5">
                        <dt class="font-mono text-xs text-slate-700">{{ $key }}</dt>
                        <dd class="font-mono text-xs text-slate-500">
                            {{ \Illuminate\Support\Str::contains($key, ['PASSWORD', 'URL']) ? str_repeat('•', 12) : $value }}
                        </dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @endif
</div>

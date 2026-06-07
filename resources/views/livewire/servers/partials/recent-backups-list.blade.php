@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\ServerDatabaseBackup> $backups */
    $backups = $backups ?? collect();
@endphp
<div class="{{ $card ?? 'dply-card overflow-hidden' }} overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge>
            <x-heroicon-o-circle-stack class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Backups') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Recent backups') }}</h2>
        </div>
    </div>
    <div class="px-6 py-6 sm:px-7">
    @if ($backups->isEmpty())
        <x-empty-state
            borderless
            icon="heroicon-o-archive-box"
            tone="sage"
            :title="__('No backups yet')"
            :description="__('Run Backup from a database row on Overview. Completed exports appear here for download or delete.')"
        >
            <x-slot:actions>
                <button
                    type="button"
                    wire:click="setEngineSubtab('overview')"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                >
                    <x-heroicon-o-arrow-left class="h-4 w-4" aria-hidden="true" />
                    {{ __('Go to Overview') }}
                </button>
            </x-slot:actions>
        </x-empty-state>
    @else
        <div class="overflow-x-auto rounded-xl border border-brand-ink/10">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-brand-sand/40 text-left text-xs font-semibold uppercase tracking-wide text-brand-mist">
                    <tr>
                        <th class="px-4 py-3">{{ __('Date') }}</th>
                        <th class="px-4 py-3">{{ __('Database') }}</th>
                        <th class="px-4 py-3">{{ __('Size') }}</th>
                        <th class="px-4 py-3">{{ __('Stored') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-end">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/10 bg-white">
                    @foreach ($backups as $backup)
                        <tr wire:key="recent-backup-{{ $backup->id }}">
                            <td class="whitespace-nowrap px-4 py-3 text-brand-moss">
                                {{ $backup->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 font-mono text-brand-ink">
                                {{ $backup->serverDatabase?->name ?? '—' }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-brand-moss">
                                @if ($backup->bytes)
                                    {{ \Illuminate\Support\Number::fileSize($backup->bytes, precision: 1) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-xs text-brand-moss">
                                @php
                                    $storedLabel = match ($backup->storage_kind) {
                                        'destination' => __('S3 destination'),
                                        'control_plane' => __('Dply app (legacy)'),
                                        default => __('On server'),
                                    };
                                @endphp
                                {{ $storedLabel }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">
                                @switch($backup->status)
                                    @case(\App\Models\ServerDatabaseBackup::STATUS_COMPLETED)
                                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">
                                            {{ __('Ready') }}
                                        </span>
                                        @break
                                    @case(\App\Models\ServerDatabaseBackup::STATUS_FAILED)
                                        <span class="inline-flex items-center rounded-full bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-700" title="{{ $backup->error_message }}">
                                            {{ __('Failed') }}
                                        </span>
                                        @break
                                    @default
                                        <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">
                                            {{ __('In progress') }}
                                        </span>
                                @endswitch
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-end">
                                <div class="inline-flex items-center justify-end gap-3">
                                    @if ($backup->isDownloadable())
                                        <button
                                            type="button"
                                            wire:click="downloadBackup(@js($backup->id))"
                                            class="text-xs font-medium text-brand-forest hover:underline"
                                        >
                                            {{ __('Download') }}
                                        </button>
                                    @endif
                                    <button
                                        type="button"
                                        wire:click="openConfirmActionModal('deleteDatabaseBackup', [@js($backup->id)], @js(__('Delete backup')), @js(__('Permanently remove this backup record and delete the file from storage. This cannot be undone.')), @js(__('Delete')), true)"
                                        class="text-xs font-medium text-red-700 hover:underline"
                                    >
                                        {{ __('Delete') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    </div>
</div>

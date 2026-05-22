@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\ServerDatabaseBackup> $backups */
    $backups = $backups ?? collect();
@endphp
<div class="{{ $card ?? 'dply-card overflow-hidden' }} p-6 sm:p-8">
    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Recent backups') }}</h2>
    @if ($backups->isEmpty())
        <p class="mt-3 text-sm text-brand-moss">
            {{ __('No backups yet. Click Backup on a database above to create one.') }}
        </p>
    @else
        <div class="mt-4 overflow-x-auto rounded-xl border border-brand-ink/10">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-brand-sand/40 text-left text-xs font-semibold uppercase tracking-wide text-brand-mist">
                    <tr>
                        <th class="px-4 py-3">{{ __('Date') }}</th>
                        <th class="px-4 py-3">{{ __('Database') }}</th>
                        <th class="px-4 py-3">{{ __('Size') }}</th>
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
                                @if ($backup->status === \App\Models\ServerDatabaseBackup::STATUS_COMPLETED)
                                    <button
                                        type="button"
                                        wire:click="downloadBackup(@js($backup->id))"
                                        class="text-xs font-medium text-brand-forest hover:underline"
                                    >
                                        {{ __('Download') }}
                                    </button>
                                @else
                                    <span class="text-xs text-brand-mist">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

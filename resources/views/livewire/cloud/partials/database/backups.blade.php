@php
    use App\Modules\Database\Backends\DatabaseBackend;
@endphp

@if (! $capabilities[DatabaseBackend::CAP_BACKUPS])
    @include('livewire.cloud.partials.database._unavailable', [
        'title' => __('Backups are not managed here'),
        'reason' => __('This backend does not expose its backups to dply. Restore from the provider\'s own console.'),
    ])
@else
    <div class="space-y-6">
        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
            {{ __('Restoring never touches this database. The provider builds a second cluster from the backup you pick; you compare the two and re-attach your apps yourself.') }}
        </div>

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-sm font-semibold text-slate-900">{{ __('Restore points') }}</h2>
            </div>

            @if ($backups === [])
                <p class="px-5 py-8 text-center text-sm text-slate-600">
                    {{ __('The provider reports no backups yet. A new cluster has none until its first scheduled run.') }}
                </p>
            @else
                <form wire:submit="restore">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">
                            <tr>
                                <th class="px-5 py-3"></th>
                                <th class="px-5 py-3">{{ __('Taken') }}</th>
                                <th class="px-5 py-3">{{ __('Size') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-800">
                            @foreach ($backups as $backup)
                                <tr>
                                    <td class="px-5 py-3">
                                        <input type="radio" wire:model="restoreBackupAt" value="{{ $backup['created_at'] }}"
                                            class="border-slate-300 text-slate-900 focus:ring-slate-900" />
                                    </td>
                                    <td class="px-5 py-3 font-mono text-xs text-slate-900">{{ $backup['created_at'] }}</td>
                                    <td class="px-5 py-3 text-slate-600">{{ number_format($backup['size_gigabytes'], 2) }} GB</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="flex flex-wrap items-end gap-3 border-t border-slate-200 px-5 py-4">
                        <div class="min-w-0 flex-1">
                            <label for="restore-name" class="text-xs font-semibold text-slate-700">{{ __('Name for the restored database') }}</label>
                            <input id="restore-name" type="text" wire:model="restoreName"
                                class="mt-1 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-slate-900 focus:ring-slate-900" />
                        </div>
                        <button type="submit"
                            wire:confirm="{{ __('Restore into a new database? It is billed as a second cluster until you tear it down.') }}"
                            class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                            {{ __('Restore to a new database') }}
                        </button>
                    </div>
                </form>
            @endif
        </div>
    </div>
@endif

<div>
    <x-page-header
        :title="__('Audit log')"
        :description="__('Platform-wide audit entries with filters and CSV export.')"
        flush
        compact
    />

    <div class="mb-6 flex flex-wrap items-end gap-3">
        <div class="min-w-[12rem] flex-1">
            <label for="audit-search" class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Search') }}</label>
            <input id="audit-search" type="search" wire:model.live.debounce.300ms="search" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm" placeholder="{{ __('Action, user, or org…') }}" />
        </div>
        <div class="min-w-[10rem]">
            <label for="audit-action" class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Action') }}</label>
            <select id="audit-action" wire:model.live="actionFilter" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm">
                <option value="">{{ __('All actions') }}</option>
                @foreach ($actions as $action)
                    <option value="{{ $action }}">{{ $action }}</option>
                @endforeach
            </select>
        </div>
        <div class="min-w-[12rem]">
            <label for="audit-org" class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Organization') }}</label>
            <select id="audit-org" wire:model.live="organizationFilter" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm">
                <option value="">{{ __('All organizations') }}</option>
                @foreach ($organizations as $org)
                    <option value="{{ $org->id }}">{{ $org->name }}</option>
                @endforeach
            </select>
        </div>
        <button type="button" wire:click="downloadCsv" class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium shadow-sm hover:bg-brand-sand/40">
            <x-heroicon-o-arrow-down-tray class="h-5 w-5 text-brand-moss" />
            {{ __('Export CSV') }}
        </button>
    </div>

    <div class="overflow-hidden rounded-xl border border-brand-ink/10 bg-white">
        <table class="min-w-full divide-y divide-brand-ink/10 text-left text-xs">
            <thead class="bg-brand-sand/40 text-brand-moss">
                <tr>
                    <th class="px-3 py-2 font-medium">{{ __('When') }}</th>
                    <th class="px-3 py-2 font-medium">{{ __('User') }}</th>
                    <th class="px-3 py-2 font-medium">{{ __('Organization') }}</th>
                    <th class="px-3 py-2 font-medium">{{ __('Action') }}</th>
                    <th class="px-3 py-2 font-medium">{{ __('Subject') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-brand-ink/5">
                @forelse ($logs as $log)
                    <tr wire:key="audit-{{ $log->id }}">
                        <td class="whitespace-nowrap px-3 py-2 text-brand-mist">{{ $log->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                        <td class="max-w-[10rem] truncate px-3 py-2" title="{{ $log->user?->email }}">{{ $log->user?->name ?? '—' }}</td>
                        <td class="max-w-[10rem] truncate px-3 py-2">{{ $log->organization?->name ?? '—' }}</td>
                        <td class="max-w-[12rem] truncate px-3 py-2 font-mono">{{ $log->action }}</td>
                        <td class="max-w-[14rem] truncate px-3 py-2 text-brand-moss">{{ $log->subject_summary ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-3 py-8 text-center text-brand-mist">{{ __('No audit entries match your filters.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $logs->links() }}</div>
</div>

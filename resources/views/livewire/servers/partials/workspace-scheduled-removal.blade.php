@if ($server->scheduled_deletion_at)
    <div class="rounded-2xl border border-amber-200/80 bg-amber-50/95 px-4 py-3 text-sm text-amber-950" role="status">
        <span class="font-medium">{{ __('Scheduled removal') }}</span>
        —
        {{ __('This server will be removed at the end of :date (:timezone). Cloud instances will be destroyed.', [
            'date' => $server->scheduled_deletion_at->timezone(config('app.timezone'))->toFormattedDateString(),
            'timezone' => config('app.timezone'),
        ]) }}
        @can('delete', $server)
            <span class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                <button type="button" wire:click="cancelScheduledServerRemoval" class="font-semibold text-amber-900 underline hover:no-underline">{{ __('Cancel schedule') }}</button>
                <button type="button" wire:click="openRemoveServerModal" class="font-semibold text-red-700 underline hover:no-underline">{{ __('Remove now…') }}</button>
            </span>
        @endcan
    </div>
@endif

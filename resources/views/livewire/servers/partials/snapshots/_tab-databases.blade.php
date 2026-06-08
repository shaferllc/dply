@php
    // Shared danger button, matching the Backups workspace styling.
    $btnDanger = 'inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-lg border border-red-200 bg-red-50 px-2.5 py-1.5 text-xs font-semibold text-red-700 transition hover:bg-red-100';

    // DB snapshots land on the user's own server disk (free) or their own S3
    // bucket. There's no provider-billed snapshot rate, so disk shows "Free" and
    // S3 shows a rough estimate at a generic S3-compatible rate (~$0.02/GB/mo).
    $s3RatePerGbMonth = 0.02;
    $formatSnapshotCost = function ($snap) use ($s3RatePerGbMonth): array {
        // → [label, title|null]. Null label means render an em dash.
        if ($snap->destination === \App\Models\Snapshot::DESTINATION_LOCAL_DISK) {
            return [__('Free'), __('Stored on your server’s own disk — no extra storage cost.')];
        }
        if ($snap->destination === \App\Models\Snapshot::DESTINATION_S3 && $snap->bytes !== null && $snap->bytes > 0) {
            $monthly = ($snap->bytes / 1_000_000_000) * $s3RatePerGbMonth;
            $amount = $monthly < 0.01 ? '<0.01' : number_format($monthly, 2);

            return ['~$'.$amount.'/mo', __('Approximate monthly storage on your own S3 bucket — billed by your provider, not Dply.')];
        }

        return [null, null];
    };

    $snapStatusClass = fn (string $status): string => match ($status) {
        \App\Models\Snapshot::STATUS_COMPLETED => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        \App\Models\Snapshot::STATUS_FAILED => 'bg-rose-50 text-rose-700 ring-rose-200',
        default => 'bg-amber-50 text-amber-800 ring-amber-200',
    };
@endphp
<x-server-workspace-tab-panel id="snapshots-panel-databases" labelled-by="snapshots-tab-databases" panel-class="space-y-8">
    {{-- Take a database snapshot. --}}
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-circle-stack class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0 flex-1">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Database snapshot') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Take a snapshot now') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('A point-in-time dump of a site’s database, stored to your S3 destination when configured or the server disk otherwise. Restorable from History below.') }}</p>
            </div>
        </div>

        <div class="px-6 py-6 sm:px-7">
            @if ($sites->isEmpty())
                <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-6 py-8 text-center">
                    <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-2xl bg-amber-50 text-amber-700 ring-1 ring-amber-200">
                        <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('No sites on this server') }}</p>
                    <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                        {{ __('Database snapshots capture a site’s database. Deploy a site to this server first, then return to snapshot it.') }}
                    </p>
                </div>
            @else
                <form wire:submit="takeSiteSnapshot" class="flex flex-wrap items-end gap-3">
                    <div class="min-w-0 flex-1">
                        <x-input-label for="snapshot_site_id" :value="__('Site')" />
                        <select id="snapshot_site_id" wire:model="snapshot_site_id" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm">
                            <option value="">— {{ __('Select site') }} —</option>
                            @foreach ($sites as $site)
                                <option value="{{ $site->id }}">{{ $site->name ?: $site->slug }}</option>
                            @endforeach
                        </select>
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="takeSiteSnapshot" :disabled="! $opsReady">
                        <span wire:loading.remove wire:target="takeSiteSnapshot">{{ __('Take snapshot') }}</span>
                        <span wire:loading wire:target="takeSiteSnapshot">{{ __('Queueing…') }}</span>
                    </x-primary-button>
                </form>
                @unless ($opsReady)
                    <p class="mt-3 text-[11px] text-amber-700">{{ __('This server is still provisioning — snapshots unlock once it is ready.') }}</p>
                @endunless
            @endif
        </div>
    </section>

    {{-- History. Polls while a dump is still running, then goes quiet. --}}
    <section class="dply-card overflow-hidden" @if ($snapshotsInFlight) wire:poll.10s @endif>
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-archive-box class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0 flex-1">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('History') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Recent database snapshots') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Restore overwrites the live database with the captured data — always destructive.') }}</p>
            </div>
        </div>

        <div class="px-6 py-6 sm:px-7">
            @if ($siteSnapshots->isEmpty())
                <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-6 py-8 text-center">
                    <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-archive-box class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('No database snapshots yet') }}</p>
                    <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">{{ __('Take one above. Snapshots taken automatically before destructive operations also surface here.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto rounded-xl border border-brand-ink/10">
                    <table class="min-w-full divide-y divide-brand-ink/10 text-xs">
                        <thead class="bg-brand-sand/40 text-left text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                            <tr>
                                <th class="px-4 py-3">{{ __('Taken') }}</th>
                                <th class="px-4 py-3">{{ __('Site') }}</th>
                                <th class="px-4 py-3">{{ __('Reason') }}</th>
                                <th class="px-4 py-3">{{ __('Status') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Size') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Est. cost') }}</th>
                                <th class="px-4 py-3">{{ __('Where') }}</th>
                                <th class="px-4 py-3 text-right"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/10 bg-white">
                            @foreach ($siteSnapshots as $snap)
                                @php
                                    $whereClass = $snap->destination === \App\Models\Snapshot::DESTINATION_S3
                                        ? 'bg-indigo-50 text-indigo-700 ring-indigo-200'
                                        : 'bg-brand-sand/60 text-brand-moss ring-brand-ink/10';
                                    [$snapCost, $snapCostTitle] = $formatSnapshotCost($snap);
                                    $isCompleted = $snap->status === \App\Models\Snapshot::STATUS_COMPLETED;
                                    $isPending = $snap->status === \App\Models\Snapshot::STATUS_PENDING;
                                @endphp
                                <tr wire:key="site-snapshot-{{ $snap->id }}">
                                    <td class="px-4 py-3 text-brand-moss" title="{{ $snap->created_at?->toDateTimeString() }}">{{ $snap->created_at?->diffForHumans() }}</td>
                                    <td class="px-4 py-3 font-medium text-brand-ink">{{ $snap->site?->name ?: $snap->site?->slug ?: '—' }}</td>
                                    <td class="px-4 py-3 text-brand-moss">{{ str($snap->reason)->replace('_', ' ')->ucfirst() }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $snapStatusClass($snap->status) }}">{{ $snap->status }}</span>
                                        @if ($snap->status === \App\Models\Snapshot::STATUS_FAILED && $snap->error_message)
                                            <p class="mt-1 max-w-xs truncate text-[11px] text-rose-700" title="{{ $snap->error_message }}">{{ $snap->error_message }}</p>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono tabular-nums text-brand-ink">{{ $snap->bytes !== null ? \Illuminate\Support\Number::fileSize((int) $snap->bytes) : '—' }}</td>
                                    <td class="px-4 py-3 text-right font-mono tabular-nums text-brand-moss" @if ($snapCostTitle) title="{{ $snapCostTitle }}" @endif>{{ $snapCost ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $whereClass }}">{{ $snap->destination === \App\Models\Snapshot::DESTINATION_S3 ? __('S3') : __('Disk') }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        @if ($isPending)
                                            <span class="inline-flex items-center gap-1.5 text-[11px] text-brand-moss">
                                                <x-spinner size="sm" />
                                                {{ __('Taking…') }}
                                            </span>
                                        @else
                                            <div class="inline-flex items-center gap-1">
                                                @if ($isCompleted)
                                                    <button
                                                        type="button"
                                                        wire:click="openConfirmActionModal('restoreSiteSnapshot', ['{{ $snap->id }}'], @js(__('Restore snapshot')), @js(__('Restore this snapshot? It OVERWRITES the live database for this site and cannot be undone.')), @js(__('Restore')), true)"
                                                        class="inline-flex items-center gap-1 rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1 text-[11px] font-medium text-amber-800 hover:bg-amber-100"
                                                        title="{{ __('Restore (destructive)') }}"
                                                    >
                                                        <x-heroicon-o-arrow-uturn-left class="h-3.5 w-3.5" aria-hidden="true" />
                                                        {{ __('Restore') }}
                                                    </button>
                                                @endif
                                                <button
                                                    type="button"
                                                    wire:click="openConfirmActionModal('deleteSiteSnapshot', ['{{ $snap->id }}'], @js(__('Delete snapshot record')), @js(__('Delete this snapshot record? The stored dump itself is not removed.')), @js(__('Delete')), true)"
                                                    class="{{ $btnDanger }}"
                                                    title="{{ __('Delete record') }}"
                                                >
                                                    <x-heroicon-m-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                                                    {{ __('Delete') }}
                                                </button>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </section>
</x-server-workspace-tab-panel>

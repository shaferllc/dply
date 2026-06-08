@php
    // Shared button + cost helpers, matching the Backups workspace styling.
    $btnDanger = 'inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-lg border border-red-200 bg-red-50 px-2.5 py-1.5 text-xs font-semibold text-red-700 transition hover:bg-red-100';

    // Per-image monthly storage estimate, in the provider's billing currency.
    // Informational only — the image sits on the user's own cloud account.
    $imageRate = $server->provider?->imageSnapshotRatePerGbMonth();
    $formatImageCost = function (?int $bytes) use ($imageRate): ?string {
        if ($imageRate === null || $bytes === null || $bytes <= 0) {
            return null;
        }
        $monthly = ($bytes / 1_000_000_000) * $imageRate['rate'];
        $symbol = ['USD' => '$', 'EUR' => '€'][$imageRate['currency']] ?? '';
        $amount = $monthly < 0.01 ? '<0.01' : number_format($monthly, 2);

        return $symbol.$amount;
    };
@endphp
<x-server-workspace-tab-panel id="snapshots-panel-images" labelled-by="snapshots-tab-images" panel-class="space-y-8">
    {{-- Capture a new image. --}}
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-camera class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0 flex-1">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Server image') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Capture a full-disk image') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('A complete, restorable image of this server taken through :provider. Stored on your cloud account and billed by them.', ['provider' => $server->provider?->label() ?? __('your provider')]) }}</p>
            </div>
        </div>

        <div class="px-6 py-6 sm:px-7">
            @if (! $imagesSupported)
                <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-6 py-8 text-center">
                    <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-2xl bg-brand-sand/60 text-brand-moss ring-1 ring-brand-ink/10">
                        <x-heroicon-o-no-symbol class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('Not available on :provider', ['provider' => $server->provider?->label() ?? __('this provider')]) }}</p>
                    <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                        {{ __('Full-disk server images are available on DigitalOcean and Hetzner. Use the Cache or Databases tabs to snapshot specific services on this server instead.') }}
                    </p>
                </div>
            @else
                <form wire:submit="createServerImage" class="space-y-2">
                    <x-input-label for="new_image_name" :value="__('Image name')" />
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <x-text-input
                            id="new_image_name"
                            wire:model="new_image_name"
                            class="block w-full text-sm sm:flex-1"
                            :placeholder="\Illuminate\Support\Str::slug($server->name ?: 'server').'-'.now()->format('Y-m-d-His')"
                        />
                        <x-primary-button type="submit" class="shrink-0 justify-center" wire:loading.attr="disabled" wire:target="createServerImage" :disabled="! $opsReady">
                            <span wire:loading.remove wire:target="createServerImage">{{ __('Create image now') }}</span>
                            <span wire:loading wire:target="createServerImage">{{ __('Queueing…') }}</span>
                        </x-primary-button>
                    </div>
                    <p class="text-[11px] text-brand-moss">{{ __('Auto-named with the date & time — edit to rename, or clear to regenerate on save.') }}</p>
                </form>
                @unless ($opsReady)
                    <p class="mt-3 text-[11px] text-amber-700">{{ __('This server is still provisioning — imaging unlocks once it is ready.') }}</p>
                @endunless
            @endif
        </div>
    </section>

    {{-- History. Polls while a capture is still running, then goes quiet. --}}
    <section class="dply-card overflow-hidden" @if ($imagesInFlight) wire:poll.10s @endif>
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-archive-box class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0 flex-1">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('History') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Server images') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Images captured from this server. Deleting one removes it from your cloud account.') }}</p>
            </div>
        </div>

        <div class="px-6 py-6 sm:px-7">
            @if ($serverImages->isEmpty())
                <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-6 py-8 text-center">
                    <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-camera class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('No images yet') }}</p>
                    <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">{{ __('Capture one above. Images take a few minutes to complete and appear here with their size and status.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto rounded-xl border border-brand-ink/10">
                    <table class="min-w-full divide-y divide-brand-ink/10 text-xs">
                        <thead class="bg-brand-sand/40 text-left text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                            <tr>
                                <th class="px-4 py-3">{{ __('Started') }}</th>
                                <th class="px-4 py-3">{{ __('Name') }}</th>
                                <th class="px-4 py-3">{{ __('Status') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Size') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Est. cost') }}</th>
                                <th class="px-4 py-3">{{ __('Region') }}</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/10 bg-white">
                            @foreach ($serverImages as $image)
                                @php
                                    $statusClass = match ($image->status) {
                                        'completed' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                        'failed' => 'bg-rose-50 text-rose-700 ring-rose-200',
                                        'creating' => 'bg-sky-50 text-sky-700 ring-sky-200',
                                        default => 'bg-brand-sand/60 text-brand-moss ring-brand-ink/10',
                                    };
                                @endphp
                                <tr wire:key="server-image-{{ $image->id }}">
                                    <td class="px-4 py-3 text-brand-moss" title="{{ $image->created_at?->toDateTimeString() }}">{{ $image->created_at?->diffForHumans() }}</td>
                                    <td class="px-4 py-3 font-medium text-brand-ink">{{ $image->name }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $statusClass }}">{{ $image->status }}</span>
                                        @if ($image->status === 'failed' && $image->error_message)
                                            <p class="mt-1 max-w-md truncate text-[11px] text-rose-700" title="{{ $image->error_message }}">{{ $image->error_message }}</p>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono tabular-nums text-brand-ink">{{ $image->bytes !== null ? \Illuminate\Support\Number::fileSize((int) $image->bytes) : '—' }}</td>
                                    @php $imageCost = $formatImageCost($image->bytes); @endphp
                                    <td class="px-4 py-3 text-right font-mono tabular-nums text-brand-moss" @if ($imageCost !== null) title="{{ __('Estimated monthly storage on :provider — billed by them, not Dply.', ['provider' => $server->provider?->label() ?? __('your provider')]) }}" @endif>{{ $imageCost !== null ? $imageCost.'/mo' : '—' }}</td>
                                    <td class="px-4 py-3 text-brand-moss">{{ $image->region ?? '—' }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <button
                                            type="button"
                                            wire:click="openConfirmActionModal('deleteServerImage', ['{{ $image->id }}'], @js(__('Delete image')), @js(__('Delete this image? This removes it from your cloud account and cannot be undone.')), @js(__('Delete image')), true)"
                                            class="{{ $btnDanger }}"
                                            title="{{ __('Delete image') }}"
                                        >
                                            <x-heroicon-m-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            {{ __('Delete') }}
                                        </button>
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

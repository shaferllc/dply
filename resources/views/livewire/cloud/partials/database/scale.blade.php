@php
    use App\Modules\Database\Backends\DatabaseBackend;
    use App\Support\Servers\ManagedDatabaseSizeCatalog;

    $currentSlug = $database->backendSizeSlug();
    $resizingTo = $database->meta['resizing_to'] ?? null;
@endphp

@if (! $capabilities[DatabaseBackend::CAP_RESIZE])
    @include('livewire.cloud.partials.database._unavailable', [
        'title' => __('This database cannot be resized from dply'),
        'reason' => __('Its backend has no in-place resize API. Scale it from the provider\'s own console, or restore into a larger database.'),
    ])
@else
    <div class="space-y-6">
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <p class="font-semibold">{{ __('Resizing takes the cluster offline') }}</p>
            <p class="mt-1">{{ __('The provider moves the data to new hardware; connections drop for the duration. Put attached apps in maintenance mode first if a failed write would matter.') }}</p>
        </div>

        @if ($resizingTo)
            <div class="rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900">
                {{ __('A resize to :size is already in progress.', ['size' => ManagedDatabaseSizeCatalog::label((string) $resizingTo)]) }}
            </div>
        @endif

        <form wire:submit="scale" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm font-semibold text-slate-900">{{ __('Plan') }}</p>
            <p class="mt-1 text-xs text-slate-600">
                {{ __('Currently on :size.', ['size' => ManagedDatabaseSizeCatalog::label($currentSlug)]) }}
            </p>

            @if ($sizeOptions === [])
                <p class="mt-4 text-sm text-slate-600">
                    {{ __('Could not load the provider\'s plan catalog. Reconnect the credential and try again.') }}
                </p>
            @else
                <div class="mt-3 flex flex-wrap gap-3">
                    <select wire:model="targetSize" class="min-w-0 flex-1 rounded-xl border-slate-300 text-sm shadow-sm focus:border-slate-900 focus:ring-slate-900">
                        @foreach ($sizeOptions as $option)
                            <option value="{{ $option['value'] }}">
                                {{ $option['label'] }}@if ($option['value'] === $currentSlug) {{ ' — '.__('current') }}@endif
                            </option>
                        @endforeach
                    </select>
                    <button type="submit"
                        wire:confirm="{{ __('Resize this cluster? It will be unavailable until the move completes.') }}"
                        class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                        {{ __('Resize') }}
                    </button>
                </div>
                <p class="mt-3 text-xs text-slate-500">
                    {{ __('Providers do not shrink disks. Moving down a plan is refused when the data no longer fits.') }}
                </p>
            @endif
        </form>
    </div>
@endif

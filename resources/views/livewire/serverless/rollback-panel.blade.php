<div class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge>
            <x-heroicon-o-arrow-path class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Rollback') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Recent artifacts') }}</h2>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Re-deploy a previous build without rebuilding — use it to revert a bad deploy.') }}</p>
        </div>
    </div>

    <div class="px-6 py-6 sm:px-7 space-y-4">
    @if (count($history) < 2)
        <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/30 px-4 py-3 text-sm text-brand-moss">
            {{ __('No earlier deploy to roll back to yet — artifacts appear here as you deploy.') }}
        </div>
    @else
        <ul class="divide-y divide-brand-ink/10">
            @foreach ($history as $index => $entry)
                <li class="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-brand-ink">
                            {{ __('Revision :rev', ['rev' => $entry['revision_id'] ?? '—']) }}
                            @if ($index === 0)
                                <span class="ml-1 inline-flex items-center rounded-md bg-brand-forest/15 px-2 py-0.5 text-[11px] font-semibold text-brand-forest">{{ __('Live') }}</span>
                            @endif
                        </p>
                        <p class="mt-0.5 text-xs text-brand-moss">
                            @if (! empty($entry['deployed_at']))
                                {{ __('Deployed :ago', ['ago' => \Illuminate\Support\Carbon::parse($entry['deployed_at'])->diffForHumans()]) }}
                            @endif
                            <span class="font-mono text-brand-moss/50"> · {{ basename((string) ($entry['artifact_path'] ?? '')) }}</span>
                        </p>
                    </div>
                    @if ($index > 0)
                        <button type="button" wire:click="rollback({{ $index }})" wire:loading.attr="disabled"
                                class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:border-brand-sage/40">
                            {{ __('Roll back to this') }}
                        </button>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
    </div>
</div>

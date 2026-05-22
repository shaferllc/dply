<div class="dply-card p-6 sm:p-8 space-y-4">
    <div>
        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-moss">{{ __('Rollback') }}</p>
        <h2 class="mt-1 text-lg font-bold text-brand-ink">{{ __('Recent artifacts') }}</h2>
        <p class="mt-1 text-sm text-brand-moss">{{ __('Re-deploy a previous build without rebuilding — use it to revert a bad deploy.') }}</p>
    </div>

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

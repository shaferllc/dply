<section class="space-y-6">
    <div class="dply-card p-6 sm:p-8">
        <h2 class="text-base font-semibold text-brand-ink">{{ __('Invocation URLs') }}</h2>
        <p class="mt-1 text-sm text-brand-moss">
            {{ __('Every public address this function answers on. The raw DigitalOcean URL skips dply\'s edge entirely (no redirects, no headers, no CORS handling); use it as a fallback only.') }}
        </p>

        @if (empty($invocationUrls))
            <div class="mt-5 rounded-lg border border-dashed border-brand-ink/15 bg-brand-sand/20 p-6 text-center text-sm text-brand-moss">
                {{ __('This function has not been deployed yet — invocation URLs appear here once the first deploy completes.') }}
            </div>
        @else
            <ul class="mt-5 divide-y divide-brand-ink/10 rounded-lg border border-brand-ink/10">
                @foreach ($invocationUrls as $entry)
                    @php
                        $scopeClasses = match ($entry['scope']) {
                            'upstream' => 'bg-amber-100 text-amber-900',
                            'edge' => 'bg-emerald-100 text-emerald-900',
                            'custom' => 'bg-sky-100 text-sky-900',
                            default => 'bg-brand-sand/40 text-brand-moss',
                        };
                    @endphp
                    <li class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-semibold text-brand-ink">{{ $entry['label'] }}</span>
                                <span class="inline-flex items-center rounded-full {{ $scopeClasses }} px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em]">{{ $entry['scope'] }}</span>
                            </div>
                            <code class="mt-1 block break-all font-mono text-xs text-brand-moss">{{ $entry['url'] }}</code>
                        </div>
                        <button
                            type="button"
                            x-data
                            x-on:click="navigator.clipboard.writeText('{{ $entry['url'] }}')"
                            class="shrink-0 inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                        >
                            <x-heroicon-o-clipboard class="h-3.5 w-3.5" />
                            {{ __('Copy') }}
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <section class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-6 text-sm text-brand-moss">
        <p class="font-medium text-brand-ink">{{ __('How requests flow') }}</p>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            <li>{{ __('Edge requests pass through redirects → CORS preflight → upstream invocation → response decoration.') }}</li>
            <li>{{ __('Raw DigitalOcean URL bypasses dply entirely — no redirects, no custom headers, no CORS handling. Useful for ops debugging.') }}</li>
            <li>{{ __('Custom domains appear here only after their DNS status flips to ready on the Custom domains tab.') }}</li>
        </ul>
    </section>
</section>

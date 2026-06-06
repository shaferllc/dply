    @if ($parserErrors !== [])
        <div class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 bg-rose-50 px-5 py-4">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-rose-100 text-rose-700 ring-rose-200">
                    <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-700">{{ __('Parse error') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-rose-900">{{ __('The cached .env has parse errors') }}</h3>
                    <ul class="mt-1 list-inside list-disc text-sm text-rose-800">
                        @foreach ($parserErrors as $err)
                            <li class="font-mono text-xs">{{ $err }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @endif

<section class="space-y-6">
    <div class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-document-text class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Headers') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Static response headers') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Headers merged onto every response the edge proxy returns. Useful for X-Frame-Options, Strict-Transport-Security, custom cache hints, etc. Content-Type, Cache-Control, and Location are reserved — set those in your function.') }}
                </p>
            </div>
        </div>

        <div class="px-6 py-6 sm:px-7">
        <form wire:submit.prevent="addHeader" class="grid gap-3 sm:grid-cols-12">
            <label class="sm:col-span-4 text-sm">
                <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Name') }}</span>
                <input
                    type="text"
                    wire:model="newHeaderName"
                    placeholder="X-Frame-Options"
                    class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink"
                />
            </label>
            <label class="sm:col-span-7 text-sm">
                <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Value') }}</span>
                <input
                    type="text"
                    wire:model="newHeaderValue"
                    placeholder="DENY"
                    class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink"
                />
            </label>
            <div class="sm:col-span-1 flex items-end">
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="addHeader"
                    class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-brand-ink px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                >
                    <x-heroicon-o-plus class="h-4 w-4" />
                </button>
            </div>
        </form>

        @if (! empty($headers))
            <ul class="mt-4 divide-y divide-brand-ink/10 rounded-lg border border-brand-ink/10">
                @foreach ($headers as $index => $header)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-2" wire:key="header-{{ $index }}">
                        <div class="min-w-0 flex-1 font-mono text-xs">
                            <span class="text-brand-ink">{{ $header['name'] }}:</span>
                            <span class="ml-1 text-brand-moss">{{ $header['value'] }}</span>
                        </div>
                        <button
                            type="button"
                            wire:click="removeHeader({{ $index }})"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-2.5 py-1 text-xs font-semibold text-rose-900 shadow-sm hover:bg-rose-50"
                        >
                            <x-heroicon-o-trash class="h-3.5 w-3.5" />
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
        </div>
    </div>

    <div class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-shield-check class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('CORS') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('CORS policy') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('When enabled, the proxy short-circuits OPTIONS preflights and decorates real responses with the CORS headers you configure.') }}
                </p>
            </div>
        </div>

        <div class="px-6 py-6 sm:px-7">
        <form wire:submit.prevent="saveCors" class="space-y-4">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Enable CORS handling at the edge') }}</p>
                    <p class="text-xs text-brand-moss">{{ __('Disable this if your function emits CORS headers itself.') }}</p>
                </div>
                <x-toggle-switch
                    wire:model="corsEnabled"
                    :enabled="$corsEnabled"
                    :on-label="__('Enabled')"
                    :off-label="__('Disabled')"
                />
            </div>

            <label class="block text-sm">
                <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Allowed origins (comma-separated, or *)') }}</span>
                <input
                    type="text"
                    wire:model="corsOrigins"
                    placeholder="https://app.acme.com, https://staging.acme.com"
                    class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink"
                />
            </label>

            <label class="block text-sm">
                <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Allowed methods') }}</span>
                <input
                    type="text"
                    wire:model="corsMethods"
                    placeholder="GET, POST, OPTIONS"
                    class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink"
                />
            </label>

            <label class="block text-sm">
                <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Allowed request headers') }}</span>
                <input
                    type="text"
                    wire:model="corsHeaders"
                    placeholder="Content-Type, Authorization"
                    class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink"
                />
            </label>

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        wire:model="corsAllowCredentials"
                        class="h-4 w-4 rounded border-brand-ink/20 text-brand-ink focus:ring-brand-ink"
                    />
                    <span class="text-brand-ink">{{ __('Allow credentials') }}</span>
                </label>
                <label class="block text-sm">
                    <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Preflight max age (seconds)') }}</span>
                    <input
                        type="number"
                        min="0"
                        wire:model="corsMaxAge"
                        class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink"
                    />
                </label>
            </div>

            <div class="flex justify-end">
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="saveCors"
                    class="inline-flex items-center gap-2 rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                >
                    <x-heroicon-o-check class="h-4 w-4" />
                    <span wire:loading.remove wire:target="saveCors">{{ __('Save CORS settings') }}</span>
                    <span wire:loading wire:target="saveCors">{{ __('Saving…') }}</span>
                </button>
            </div>
        </form>
        </div>
    </div>
</section>

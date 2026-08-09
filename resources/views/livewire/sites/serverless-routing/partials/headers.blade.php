{{-- Headers & CORS tab — hairline strips inside the parent Routing card. --}}
@php
    $panelPad = 'px-3 py-2.5 sm:px-4';
    $stripHead = 'border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4';
@endphp

<div class="border-b border-brand-ink/10">
    <div class="{{ $stripHead }} flex flex-wrap items-center gap-x-2 gap-y-1">
        <h3 class="flex shrink-0 items-center gap-1.5 text-sm font-semibold text-brand-ink">
            <x-heroicon-o-document-text class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
            {{ __('Static response headers') }}
        </h3>
        <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
        <p class="min-w-0 flex-1 truncate text-[11px] text-brand-mist" title="{{ __('Merged onto every proxied response. Content-Type, Cache-Control, and Location are reserved.') }}">
            {{ __('Merged on every response · Content-Type / Cache-Control / Location reserved') }}
        </p>
    </div>
    <div class="{{ $panelPad }} space-y-2.5">
        <form wire:submit.prevent="addHeader" class="grid gap-2 sm:grid-cols-12 sm:items-end">
            <label class="sm:col-span-4 text-sm">
                <span class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Name') }}</span>
                <input
                    type="text"
                    wire:model="newHeaderName"
                    placeholder="X-Frame-Options"
                    class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 font-mono text-xs shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink"
                />
            </label>
            <label class="sm:col-span-7 text-sm">
                <span class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Value') }}</span>
                <input
                    type="text"
                    wire:model="newHeaderValue"
                    placeholder="DENY"
                    class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 font-mono text-xs shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink"
                />
            </label>
            <div class="sm:col-span-1 flex items-end">
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="addHeader"
                    class="inline-flex w-full items-center justify-center gap-1 rounded-lg bg-brand-ink px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                    title="{{ __('Add header') }}"
                >
                    <x-heroicon-o-plus class="h-3.5 w-3.5" />
                </button>
            </div>
        </form>

        @if (! empty($headers))
            <ul class="divide-y divide-brand-ink/10 rounded-lg border border-brand-ink/10">
                @foreach ($headers as $index => $header)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-2.5 py-1.5" wire:key="header-{{ $index }}">
                        <div class="min-w-0 flex-1 font-mono text-[11px]">
                            <span class="text-brand-ink">{{ $header['name'] }}:</span>
                            <span class="ml-1 text-brand-moss">{{ $header['value'] }}</span>
                        </div>
                        <button
                            type="button"
                            wire:click="removeHeader({{ $index }})"
                            class="inline-flex items-center gap-1 rounded-lg border border-rose-200 bg-white px-2 py-1 text-[11px] font-semibold text-rose-900 shadow-sm hover:bg-rose-50"
                            title="{{ __('Remove') }}"
                        >
                            <x-heroicon-o-trash class="h-3.5 w-3.5" />
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>

<div>
    <div class="{{ $stripHead }} flex flex-wrap items-center gap-x-2 gap-y-1">
        <h3 class="flex shrink-0 items-center gap-1.5 text-sm font-semibold text-brand-ink">
            <x-heroicon-o-shield-check class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
            {{ __('CORS policy') }}
        </h3>
        <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
        <p class="min-w-0 flex-1 truncate text-[11px] text-brand-mist" title="{{ __('When enabled, the proxy short-circuits OPTIONS preflights and decorates responses.') }}">
            {{ __('OPTIONS preflight + response decoration at the edge') }}
        </p>
    </div>
    <form wire:submit.prevent="saveCors" class="{{ $panelPad }} space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Enable CORS at the edge') }}</p>
                <p class="text-[11px] text-brand-moss">{{ __('Disable if your function emits CORS headers itself.') }}</p>
            </div>
            <x-toggle-switch
                wire:model="corsEnabled"
                :enabled="$corsEnabled"
                :on-label="__('Enabled')"
                :off-label="__('Disabled')"
            />
        </div>

        <label class="block text-sm">
            <span class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Allowed origins (comma-separated, or *)') }}</span>
            <input
                type="text"
                wire:model="corsOrigins"
                placeholder="https://app.acme.com, https://staging.acme.com"
                class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 font-mono text-xs shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink"
            />
        </label>

        <label class="block text-sm">
            <span class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Allowed methods') }}</span>
            <input
                type="text"
                wire:model="corsMethods"
                placeholder="GET, POST, OPTIONS"
                class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 font-mono text-xs shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink"
            />
        </label>

        <label class="block text-sm">
            <span class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Allowed request headers') }}</span>
            <input
                type="text"
                wire:model="corsHeaders"
                placeholder="Content-Type, Authorization"
                class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 font-mono text-xs shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink"
            />
        </label>

        <div class="grid gap-2 sm:grid-cols-2 sm:items-end">
            <label class="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    wire:model="corsAllowCredentials"
                    class="h-3.5 w-3.5 rounded border-brand-ink/20 text-brand-ink focus:ring-brand-ink"
                />
                <span class="text-xs text-brand-ink">{{ __('Allow credentials') }}</span>
            </label>
            <label class="block text-sm">
                <span class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Preflight max age (seconds)') }}</span>
                <input
                    type="number"
                    min="0"
                    wire:model="corsMaxAge"
                    class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 font-mono text-xs shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink"
                />
            </label>
        </div>

        <div class="flex justify-end">
            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="saveCors"
                class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
            >
                <x-heroicon-o-check class="h-3.5 w-3.5" />
                <span wire:loading.remove wire:target="saveCors">{{ __('Save CORS') }}</span>
                <span wire:loading wire:target="saveCors">{{ __('Saving…') }}</span>
            </button>
        </div>
    </form>
</div>

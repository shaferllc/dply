{{-- Redirects tab — hairline strips inside the parent Routing card. --}}
@php
    $panelPad = 'px-3 py-2.5 sm:px-4';
    $stripHead = 'border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4';
@endphp

<div class="border-b border-brand-ink/10">
    <div class="{{ $stripHead }} flex flex-wrap items-center gap-x-2 gap-y-1">
        <h3 class="flex shrink-0 items-center gap-1.5 text-sm font-semibold text-brand-ink">
            <x-heroicon-o-arrow-uturn-right class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
            {{ __('Add a redirect') }}
        </h3>
        <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
        <p class="min-w-0 flex-1 truncate text-xs text-brand-mist" title="{{ __('The edge proxy applies redirects before forwarding upstream. First match wins.') }}">
            {{ __('Applied before upstream · first match wins') }}
        </p>
    </div>
    <form wire:submit.prevent="addRedirect" class="{{ $panelPad }} grid gap-2 sm:grid-cols-12 sm:items-end">
        <label class="sm:col-span-4 text-sm">
            <span class="block text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('From path') }}</span>
            <input
                type="text"
                wire:model="newRedirectFrom"
                placeholder="/old-path"
                class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 font-mono text-xs shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink"
            />
        </label>
        <label class="sm:col-span-5 text-sm">
            <span class="block text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Target URL or path') }}</span>
            <input
                type="text"
                wire:model="newRedirectTo"
                placeholder="https://new.example.com/landing"
                class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 font-mono text-xs shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink"
            />
        </label>
        <label class="sm:col-span-2 text-sm">
            <span class="block text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Status') }}</span>
            <select
                wire:model="newRedirectStatus"
                class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink"
            >
                <option value="301">301</option>
                <option value="302">302</option>
                <option value="307">307</option>
                <option value="308">308</option>
            </select>
        </label>
        <div class="sm:col-span-1 flex items-end">
            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="addRedirect"
                class="inline-flex w-full items-center justify-center gap-1 rounded-lg bg-brand-ink px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                title="{{ __('Add redirect') }}"
            >
                <x-heroicon-o-plus class="h-3.5 w-3.5" />
            </button>
        </div>
    </form>
</div>

<div>
    <div class="{{ $stripHead }} flex flex-wrap items-center gap-x-2 gap-y-1">
        <h3 class="flex shrink-0 items-center gap-1.5 text-sm font-semibold text-brand-ink">
            <x-heroicon-o-list-bullet class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
            {{ __('Active redirects') }}
        </h3>
        <span class="ml-auto shrink-0 text-xs tabular-nums text-brand-moss">{{ trans_choice('{0} none|{1} :count redirect|[2,*] :count redirects', count($redirects), ['count' => count($redirects)]) }}</span>
    </div>

    @if (empty($redirects))
        <div class="{{ $panelPad }} text-center text-xs text-brand-moss">
            {{ __('No redirects configured. Add one above to start path-based redirects at the edge.') }}
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="text-left text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">
                    <tr>
                        <th class="px-3 py-1.5 pr-3 sm:px-4">{{ __('From') }}</th>
                        <th class="py-1.5 pr-3">{{ __('To') }}</th>
                        <th class="py-1.5 pr-3">{{ __('Status') }}</th>
                        <th class="py-1.5 pr-3 text-right sm:pr-4">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/10">
                    @foreach ($redirects as $index => $redirect)
                        <tr wire:key="redirect-{{ $index }}">
                            <td class="px-3 py-2 pr-3 font-mono text-xs text-brand-ink sm:px-4">{{ $redirect['from'] }}</td>
                            <td class="py-2 pr-3 break-all font-mono text-xs text-brand-ink">{{ $redirect['to'] }}</td>
                            <td class="py-2 pr-3 font-mono text-xs text-brand-moss">{{ $redirect['status'] }}</td>
                            <td class="py-2 pr-3 text-right sm:pr-4">
                                <button
                                    type="button"
                                    wire:click="openConfirmActionModal('removeRedirect', @js([$index]), @js(__('Remove redirect')), @js(__('Remove :from → :to?', ['from' => $redirect['from'], 'to' => $redirect['to']])), @js(__('Remove')), true)"
                                    class="inline-flex items-center gap-1 rounded-lg border border-rose-200 bg-white px-2 py-1 text-xs font-semibold text-rose-900 shadow-sm hover:bg-rose-50"
                                >
                                    <x-heroicon-o-trash class="h-3.5 w-3.5" />
                                    {{ __('Remove') }}
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

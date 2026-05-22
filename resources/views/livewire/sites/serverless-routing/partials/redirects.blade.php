<section class="space-y-6">
    <div class="dply-card p-6 sm:p-8">
        <h2 class="text-base font-semibold text-brand-ink">{{ __('Add a redirect') }}</h2>
        <p class="mt-1 text-sm text-brand-moss">
            {{ __('The edge proxy applies redirects before forwarding upstream. First match wins, in the order they appear below.') }}
        </p>

        <form wire:submit.prevent="addRedirect" class="mt-5 grid gap-3 sm:grid-cols-12">
            <label class="sm:col-span-4 text-sm">
                <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('From path') }}</span>
                <input
                    type="text"
                    wire:model="newRedirectFrom"
                    placeholder="/old-path"
                    class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink"
                />
            </label>
            <label class="sm:col-span-5 text-sm">
                <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Target URL or path') }}</span>
                <input
                    type="text"
                    wire:model="newRedirectTo"
                    placeholder="https://new.example.com/landing"
                    class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink"
                />
            </label>
            <label class="sm:col-span-2 text-sm">
                <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Status') }}</span>
                <select
                    wire:model="newRedirectStatus"
                    class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink"
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
                    class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-brand-ink px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                >
                    <x-heroicon-o-plus class="h-4 w-4" />
                </button>
            </div>
        </form>
    </div>

    <div class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
        <header class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 class="text-base font-semibold text-brand-ink">{{ __('Active redirects') }}</h2>
            <span class="text-xs text-brand-moss">{{ trans_choice('{0} no redirects|{1} :count redirect|[2,*] :count redirects', count($redirects), ['count' => count($redirects)]) }}</span>
        </header>

        @if (empty($redirects))
            <div class="mt-4 rounded-lg border border-dashed border-brand-ink/15 bg-brand-sand/20 p-6 text-center text-sm text-brand-moss">
                {{ __('No redirects configured. Add one above to start routing path-based redirects at the edge.') }}
            </div>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                    <thead class="text-left text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-moss">
                        <tr>
                            <th class="py-2 pr-3">{{ __('From') }}</th>
                            <th class="py-2 pr-3">{{ __('To') }}</th>
                            <th class="py-2 pr-3">{{ __('Status') }}</th>
                            <th class="py-2 text-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-ink/10">
                        @foreach ($redirects as $index => $redirect)
                            <tr wire:key="redirect-{{ $index }}">
                                <td class="py-2 pr-3 font-mono text-xs text-brand-ink">{{ $redirect['from'] }}</td>
                                <td class="py-2 pr-3 break-all font-mono text-xs text-brand-ink">{{ $redirect['to'] }}</td>
                                <td class="py-2 pr-3 font-mono text-xs text-brand-moss">{{ $redirect['status'] }}</td>
                                <td class="py-2 text-right">
                                    <button
                                        type="button"
                                        wire:click="removeRedirect({{ $index }})"
                                        wire:confirm="{{ __('Remove this redirect?') }}"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-2.5 py-1 text-xs font-semibold text-rose-900 shadow-sm hover:bg-rose-50"
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
</section>

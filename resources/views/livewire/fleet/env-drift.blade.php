<div class="mx-auto max-w-7xl px-6 py-10">
    @include('livewire.fleet._tabs')
    <header class="mb-6 border-b border-brand-ink/10 pb-4">
        <h1 class="text-2xl font-semibold text-brand-ink">{{ __('Cross-product env drift') }}</h1>
        <p class="mt-1 text-sm text-brand-moss">
            {{ __('Sites that share a Git repo across BYO, Cloud, and Edge — and whether their environment variables agree. The first column is the baseline; later columns are compared against it.') }}
        </p>
    </header>

    <div class="mb-6 flex flex-wrap items-center gap-3">
        <div class="min-w-[18rem] flex-1">
            <label for="drift_search" class="block text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Filter by repo') }}</label>
            <input id="drift_search" type="search" wire:model.live.debounce.300ms="search" placeholder="github.com/owner/repo" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
        </div>
        <label class="inline-flex items-center gap-2 self-end text-sm text-brand-moss">
            <input type="checkbox" wire:model.live="hideClean" class="rounded border-brand-ink/20 text-brand-forest focus:ring-brand-forest" />
            {{ __('Hide repos with no drift') }}
        </label>
        <button type="button" wire:click="toggleReveal" class="self-end rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">
            {{ $reveal ? __('Hide values') : __('Reveal values') }}
        </button>
        @if ($search !== '' || $hideClean)
            <button type="button" wire:click="clearFilters" class="self-end text-xs font-semibold text-brand-moss hover:text-brand-ink">
                {{ __('Clear filters') }}
            </button>
        @endif
    </div>

    @if ($totalGroups === 0)
        <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/20 p-8 text-center text-sm text-brand-moss">
            <p class="font-medium text-brand-ink">{{ __('No cross-product repos yet.') }}</p>
            <p class="mt-1">{{ __('Drift comparison kicks in when at least two sites in the org point at the same Git repo — for example, a Cloud API and an Edge front-end of the same product.') }}</p>
        </div>
    @elseif ($groups === [])
        <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/20 p-8 text-center text-sm text-brand-moss">
            {{ __('No repos match the current filters.') }}
        </div>
    @else
        <p class="mb-4 text-xs text-brand-moss">
            {{ trans_choice('{1} 1 cross-product repo|[2,*] :count cross-product repos', $totalGroups, ['count' => $totalGroups]) }} · {{ $cleanGroups }} {{ __('clean') }} · {{ $totalGroups - $cleanGroups }} {{ __('drifted') }}
        </p>
        <div class="space-y-6">
            @foreach ($groups as $group)
                @php($matrix = $group['matrix'])
                <section class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-3">
                        <div class="min-w-0">
                            <p class="font-mono text-sm text-brand-ink truncate">{{ $group['repo'] }}</p>
                            <p class="mt-0.5 text-xs text-brand-moss">
                                {{ trans_choice('{1} 1 environment|[2,*] :count environments', count($group['envs']), ['count' => count($group['envs'])]) }} ·
                                {{ trans_choice('{1} 1 key|[2,*] :count keys', count($matrix['keys']), ['count' => count($matrix['keys'])]) }}
                            </p>
                        </div>
                        @if ($group['has_drift'])
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-900 ring-1 ring-amber-200">
                                <span class="h-1.5 w-1.5 rounded-full bg-amber-500" aria-hidden="true"></span>
                                {{ trans_choice('{1} 1 key drifts|[2,*] :count keys drift', $matrix['drift_keys'], ['count' => $matrix['drift_keys']]) }}
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-900 ring-1 ring-emerald-200">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                                {{ __('In sync') }}
                            </span>
                        @endif
                    </header>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                            <thead class="bg-brand-cream/60 text-left text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">
                                <tr>
                                    <th class="sticky left-0 z-10 bg-brand-cream/95 px-4 py-3 backdrop-blur">{{ __('Key') }}</th>
                                    @foreach ($group['envs'] as $env)
                                        <th class="px-4 py-3 align-top">
                                            <div class="font-semibold text-brand-ink normal-case tracking-normal">{{ $env['site']->name }}</div>
                                            <div class="mt-0.5 inline-flex items-center gap-1.5 text-[10px] font-medium uppercase tracking-wider text-brand-moss">
                                                <span class="rounded bg-brand-ink/[0.06] px-1.5 py-0.5">{{ $env['surface'] }}</span>
                                                <span>{{ $env['scope'] }}</span>
                                            </div>
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/5">
                                @foreach ($matrix['keys'] as $key)
                                    @php($cells = $matrix['rows'][$key])
                                    <tr class="hover:bg-brand-sand/10">
                                        <td class="sticky left-0 z-10 whitespace-nowrap bg-white px-4 py-2 font-mono text-xs text-brand-ink">{{ $key }}</td>
                                        @foreach ($cells as $cell)
                                            @if ($cell['status'] === 'missing')
                                                <td class="px-4 py-2 align-top">
                                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-50 px-2 py-0.5 text-[11px] font-semibold text-rose-800 ring-1 ring-rose-200">
                                                        <span class="h-1 w-1 rounded-full bg-rose-500" aria-hidden="true"></span>
                                                        {{ __('missing') }}
                                                    </span>
                                                </td>
                                            @elseif ($cell['status'] === 'differs')
                                                <td class="px-4 py-2 align-top">
                                                    <div class="flex items-center gap-1.5">
                                                        <span class="h-1.5 w-1.5 rounded-full bg-amber-500" aria-hidden="true"></span>
                                                        <span class="font-mono text-xs text-brand-ink">{{ $this->maskValue($cell['value']) }}</span>
                                                    </div>
                                                </td>
                                            @else
                                                <td class="px-4 py-2 align-top font-mono text-xs text-brand-moss">{{ $this->maskValue($cell['value']) }}</td>
                                            @endif
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</div>

<div>
    <div class="border-b border-slate-200 bg-white">
        <div class="dply-page-shell py-8">
            <x-page-header
                :eyebrow="$page['eyebrow']"
                :title="$page['title']"
                :description="$page['description']"
                doc-route="docs.index"
                flush
            >
                <x-slot name="actions">
                    <a href="{{ route('launches.create') }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">{{ __('Back to launchpad') }}</a>
                </x-slot>
            </x-page-header>
        </div>
    </div>

    <div class="py-10">
        <div class="dply-page-shell">
            @if ($page['eyebrow'] === __('Containers'))
                <section class="mb-6 rounded-2xl border border-sky-200 bg-sky-50/70 p-6">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-sky-700">{{ __('Shared runtime model') }}</p>
                    <h2 class="mt-2 text-2xl font-semibold text-slate-900">{{ __('Start local, then move the same container story remote') }}</h2>
                    <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-700">{{ __('The container lane treats local Docker, remote Docker, and remote Kubernetes as parallel targets behind one repo-first runtime concept. Dply inspects the repo once, then keeps runtime operations aligned across local and remote targets.') }}</p>
                </section>
            @endif

            <div class="grid gap-4 md:grid-cols-2">
                @foreach ($page['items'] as $item)
                    <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-xl font-semibold text-slate-900">{{ $item['title'] }}</h2>
                        <p class="mt-3 text-sm leading-6 text-slate-600">{{ $item['description'] }}</p>
                        <div class="mt-5 flex flex-wrap items-center gap-3">
                            <a
                                href="{{ $item['href'] }}"
                                wire:navigate
                                @class([
                                    'inline-flex items-center justify-center rounded-xl px-4 py-2.5 text-sm font-semibold transition',
                                    'bg-sky-600 text-white hover:bg-sky-700' => ($item['priority'] ?? null) === 'primary',
                                    'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50' => ($item['priority'] ?? null) !== 'primary',
                                ])
                            >
                                {{ $item['cta'] }}
                            </a>
                        </div>
                    </article>
                @endforeach
            </div>

            @if (($page['existing_targets'] ?? []) !== [])
                <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">{{ __('Continue on an existing local target') }}</p>
                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        @foreach ($page['existing_targets'] as $target)
                            <a href="{{ $target['href'] }}" wire:navigate class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4 transition hover:border-sky-300 hover:bg-white">
                                <p class="text-sm font-semibold text-slate-900">{{ $target['name'] }}</p>
                                <p class="mt-1 text-sm text-slate-600">{{ $target['kind'] }}</p>
                                <p class="mt-3 text-sm font-medium text-sky-700">{{ __('Create site on this target') }} →</p>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
        </div>
    </div>
</div>

<div class="space-y-6">
    {{-- dply.yaml integration banner (same pattern as Crons / Firewall / Routing) --}}
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-globe-alt class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Domains') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Custom domains') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Hostnames routed to this Edge site. Declare them in :file under `domains:` and dply auto-attaches on every deploy, or add ad-hoc below.', ['file' => $sourcePath]) }}
                </p>
            </div>
            <a
                href="{{ route('sites.edge.dply-yaml', ['server' => $site->server_id, 'site' => $site->id]) }}"
                class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-ink hover:bg-brand-sand/40"
            >
                <x-heroicon-o-arrow-down-tray class="h-3 w-3" aria-hidden="true" />
                {{ __('Generate dply.yaml') }}
            </a>
        </div>

        {{-- From dply.yaml --}}
        <div class="px-6 py-4 sm:px-8">
            <div class="flex items-baseline justify-between gap-2">
                <h4 class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('From :file', ['file' => $sourcePath]) }}</h4>
                <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Repo-managed') }}</span>
            </div>
            @if ($repoDomains !== [])
                <div class="mt-2 rounded-lg border border-brand-ink/10 p-3">
                    <ul class="space-y-1 font-mono text-xs text-brand-ink">
                        @foreach ($repoDomains as $host)
                            <li class="break-all">{{ $host }}</li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-[11px] text-brand-mist">{{ __('Auto-attached on every deploy. Removing a hostname from :file does NOT detach — detaches are explicit only.', ['file' => $sourcePath]) }}</p>
                </div>
            @else
                <p class="mt-2 text-sm text-brand-moss">
                    {{ __('No domains declared in :file. Add a `domains:` block to commit the list to your repo, or add ad-hoc below.', ['file' => $sourcePath]) }}
                </p>
                <pre class="mt-3 overflow-x-auto rounded-lg bg-brand-ink/95 px-4 py-3 font-mono text-[11px] leading-relaxed text-brand-sand"><code>domains:
  - "www.example.com"
  - "example.com"</code></pre>
            @endif
        </div>
    </section>

    @include('livewire.sites.partials.edge.domains')
</div>

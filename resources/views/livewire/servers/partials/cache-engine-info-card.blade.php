{{-- Engine information card: label, description, license/maintainer/protocol/year metadata,
     "best for" callout, and homepage/docs links. Used on the per-engine Overview subtab and
     on the not-installed / in-flight states (where there are no subtabs), plus the caches
     and databases workspaces.

     Dense head + stat strip, same treatment as the rest of the workspace: the
     tagline is the head note, the four metadata facts are a hairline strip
     instead of a bordered tile grid, and the "installed" badge rides the
     actions slot. --}}
<div class="{{ $card }}">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-information-circle"
        :title="$info['label']"
        :note="$info['tagline']"
        class="border-b border-brand-ink/10"
    >
        @if ($row ?? null)
            <x-slot:actions>
                <span class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-full bg-emerald-50 px-2 text-[11px] font-semibold text-emerald-700 ring-1 ring-emerald-200">
                    <x-heroicon-m-check-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ __('Installed on this server') }}
                </span>
            </x-slot:actions>
        @endif
    </x-workspace-panel-head>

    <p class="border-b border-brand-ink/10 px-4 py-3 text-xs leading-relaxed text-brand-ink/85 sm:px-5">{{ $info['description'] }}</p>

    <x-workspace-stat-strip class="border-b border-brand-ink/10" :stats="[
        ['label' => __('License'), 'value' => $info['license'], 'hint' => $info['license']],
        ['label' => __('Maintainer'), 'value' => $info['maintainer'], 'hint' => $info['maintainer']],
        ['label' => __('Wire protocol'), 'value' => $info['wire_protocol'], 'hint' => $info['wire_protocol']],
        ['label' => __('First released'), 'value' => $info['first_released']],
    ]" />

    <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 sm:px-5">
        <p class="min-w-0 flex-1 text-[11px] leading-relaxed text-brand-moss">
            <span class="font-semibold text-brand-ink">{{ __('Best for:') }}</span>
            {{ $info['best_for'] }}
        </p>
        <div class="flex shrink-0 flex-wrap items-center gap-1.5">
            <a href="{{ $info['homepage_url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex h-6 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-[11px] font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                <x-heroicon-m-globe-alt class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ __('Homepage') }}
                <x-heroicon-m-arrow-top-right-on-square class="h-3 w-3 shrink-0 text-brand-mist" aria-hidden="true" />
            </a>
            <a href="{{ $info['docs_url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex h-6 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-[11px] font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                <x-heroicon-m-book-open class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ __('Documentation') }}
                <x-heroicon-m-arrow-top-right-on-square class="h-3 w-3 shrink-0 text-brand-mist" aria-hidden="true" />
            </a>
        </div>
    </div>
</div>

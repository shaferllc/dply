{{-- Engine information card: label, description, license/maintainer/protocol/year metadata,
     "best for" callout, and homepage/docs links. Used on the per-engine Overview subtab and
     on the not-installed / in-flight states (where there are no subtabs). --}}
<div class="{{ $card }} p-6 sm:p-8">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h2 class="text-xl font-semibold text-brand-ink">{{ $info['label'] }}</h2>
            <p class="mt-1 text-sm text-brand-moss">{{ $info['tagline'] }}</p>
        </div>
        @if ($row ?? null)
            <span class="inline-flex h-fit items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 ring-1 ring-emerald-200">
                <x-heroicon-o-check-circle class="h-3.5 w-3.5" />
                {{ __('Installed on this server') }}
            </span>
        @endif
    </div>

    <p class="mt-4 text-sm leading-relaxed text-brand-ink/85">{{ $info['description'] }}</p>

    <dl class="mt-6 grid gap-4 rounded-xl border border-brand-ink/10 bg-brand-sand/40 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('License') }}</dt>
            <dd class="mt-1 text-xs text-brand-ink leading-snug">{{ $info['license'] }}</dd>
        </div>
        <div>
            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Maintainer') }}</dt>
            <dd class="mt-1 text-xs text-brand-ink leading-snug">{{ $info['maintainer'] }}</dd>
        </div>
        <div>
            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Wire protocol') }}</dt>
            <dd class="mt-1 text-xs text-brand-ink leading-snug">{{ $info['wire_protocol'] }}</dd>
        </div>
        <div>
            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('First released') }}</dt>
            <dd class="mt-1 text-xs text-brand-ink leading-snug">{{ $info['first_released'] }}</dd>
        </div>
    </dl>

    <div class="mt-4 flex items-start gap-3 rounded-xl border border-brand-forest/15 bg-brand-forest/5 p-3">
        <x-heroicon-o-light-bulb class="mt-0.5 h-4 w-4 shrink-0 text-brand-forest" />
        <p class="text-xs leading-relaxed text-brand-ink">
            <span class="font-semibold">{{ __('Best for:') }}</span>
            {{ $info['best_for'] }}
        </p>
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-2">
        <a href="{{ $info['homepage_url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40">
            <x-heroicon-o-globe-alt class="h-3.5 w-3.5" />
            {{ __('Homepage') }}
            <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3 text-brand-mist" />
        </a>
        <a href="{{ $info['docs_url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40">
            <x-heroicon-o-book-open class="h-3.5 w-3.5" />
            {{ __('Documentation') }}
            <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3 text-brand-mist" />
        </a>
    </div>
</div>

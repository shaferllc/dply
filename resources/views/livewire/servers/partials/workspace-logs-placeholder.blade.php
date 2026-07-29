{{--
    Lazy-load skeleton for Logs. Mirrors the merged page (hide-hero + single
    card with identity, tabs, and viewer stubs).
--}}
<x-server-workspace-layout
    :server="$server"
    active="logs"
    :title="__('Logs')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading logs…') }}</span>

        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6" aria-hidden="true">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-document-text class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Logs') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Dply activity and system log tailing for this server — live SSH reads with Reverb streaming.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-4 py-2.5" aria-hidden="true">
            @foreach ([__('Viewer'), __('Overview'), __('Sources'), __('dply Logs'), __('Alerts'), __('Activity'), __('Related')] as $i => $label)
                <span @class([
                    'inline-flex h-8 items-center rounded-lg px-3 text-xs font-semibold',
                    'bg-brand-ink text-white' => $i === 0,
                    'animate-pulse bg-brand-ink/10 text-transparent' => $i !== 0,
                ])>{{ $label }}</span>
            @endforeach
        </div>

        <div class="border-b border-brand-ink/10 px-5 py-5 sm:px-6" aria-hidden="true">
            <div class="space-y-4">
                <div class="h-10 w-full max-w-xl animate-pulse rounded-xl bg-brand-ink/10"></div>
                <div class="flex flex-wrap gap-2">
                    @foreach (range(1, 4) as $chip)
                        <span class="h-8 w-20 animate-pulse rounded-lg bg-brand-ink/10"></span>
                    @endforeach
                </div>
                <div class="h-64 w-full animate-pulse rounded-xl bg-brand-ink/10"></div>
            </div>
        </div>
    </section>
</x-server-workspace-layout>

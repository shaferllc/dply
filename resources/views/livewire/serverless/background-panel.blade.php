<div class="dply-card p-6 sm:p-8 space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-moss">{{ __('Background') }}</p>
            <h2 class="mt-1 text-base font-bold text-brand-ink">{{ __('Scheduler & queue worker') }}</h2>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('DigitalOcean Functions has no long-running process, so dply invokes this function every minute to run the Laravel scheduler (schedule:run) and drain queued jobs (queue:work).') }}
            </p>
        </div>
        <span @class([
            'inline-flex items-center rounded-md px-2.5 py-1 text-xs font-semibold',
            'bg-brand-forest/15 text-brand-forest' => $enabled,
            'bg-brand-ink/5 text-brand-moss' => ! $enabled,
        ])>
            {{ $enabled ? __('On') : __('Off') }}
        </span>
    </div>

    @if ($enabled)
        <div class="rounded-xl border border-brand-forest/20 bg-brand-forest/10 px-4 py-3 text-sm text-brand-forest">
            {{ __('Running every minute. For queued jobs, set QUEUE_CONNECTION=database (or redis) in the Environment panel and migrate the jobs table.') }}
        </div>
    @endif

    @unless ($deployed)
        <section class="dply-card overflow-hidden border-amber-200">
            <div class="border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-amber-50 text-amber-900 ring-amber-200">
                        <x-heroicon-o-shield-exclamation class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Setup') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Deploy the function first') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Ticks are skipped until the function has an invocation URL.') }}</p>
                    </div>
                </div>
            </div>
        </section>
    @endunless

    <button type="button" wire:click="toggle" wire:loading.attr="disabled"
            class="inline-flex items-center rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest disabled:opacity-70">
        {{ $enabled ? __('Disable background processing') : __('Enable background processing') }}
    </button>

    <div class="border-t border-brand-ink/10 pt-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm font-semibold text-brand-ink">{{ __('Keep warm') }}</p>
                <p class="mt-0.5 text-sm text-brand-moss">
                    {{ __('Ping the function every minute so a request rarely hits a cold start. Not needed when background processing is on — that already keeps it warm.') }}
                </p>
            </div>
            <button type="button" wire:click="toggleKeepWarm" wire:loading.attr="disabled"
                    class="inline-flex items-center rounded-xl border-2 border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink hover:border-brand-sage/40 disabled:opacity-70">
                {{ $keepWarm ? __('Disable keep-warm') : __('Enable keep-warm') }}
            </button>
        </div>
    </div>
</div>

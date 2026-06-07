    @if ($envInDocroot)
        <div class="dply-card overflow-hidden">
            <div class="flex flex-wrap items-start justify-between gap-3 bg-amber-50 px-5 py-4">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-amber-100 text-amber-700 ring-amber-200">
                        <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-700">{{ __('Exposure') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-amber-950">{{ __('Env file lives inside the docroot') }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-amber-900">
                            {{ __(':path is reachable by the webserver. The default deny rule blocks /.env over HTTP, but moving the file outside the docroot is safer if the rule is ever changed or bypassed.', ['path' => $site->effectiveEnvFilePath()]) }}
                        </p>
                    </div>
                </div>
                <button
                    type="button"
                    wire:click="relocateEnvOutsideDocroot"
                    wire:loading.attr="disabled"
                    wire:target="relocateEnvOutsideDocroot"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-semibold text-amber-900 shadow-sm hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-60"
                    title="{{ __('Move .env to /etc/dply/:slug.env (root:site-user 640) and push.', ['slug' => $site->slug]) }}"
                >
                    <x-heroicon-o-arrow-up-on-square class="h-4 w-4" />
                    {{ __('Move outside docroot') }}
                </button>
            </div>
        </div>
    @endif

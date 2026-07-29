@php
    $hasRepoErrors = $repoErrors !== [] || $repoMaint !== [];
@endphp

<div>
    {{-- Maintenance — primary ops control (live host-map republish). --}}
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        <label class="flex items-start gap-3">
            <input
                type="checkbox"
                wire:model.live="maintenance_enabled"
                class="mt-1 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest"
            />
            <span class="min-w-0">
                <span class="block text-sm font-semibold text-brand-ink">{{ __('Maintenance mode') }}</span>
                <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Visitors get a 503 until you turn this off. Takes effect immediately after Save.') }}</span>
            </span>
        </label>
    </section>

    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        <label class="block" for="error-404">
            <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('404 page') }}</span>
            <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Shown when a path isn’t found. Blank = built-in default.') }}</span>
        </label>
        <textarea
            id="error-404"
            wire:model="error_404_html"
            rows="5"
            spellcheck="false"
            class="mt-2 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest dark:border-brand-mist/20 dark:bg-zinc-900"
            placeholder="<!doctype html>…"
        ></textarea>
        @error('error_404_html') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
    </section>

    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        <label class="block" for="error-500">
            <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('500 page') }}</span>
            <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Unexpected errors. Blank = built-in default.') }}</span>
        </label>
        <textarea
            id="error-500"
            wire:model="error_500_html"
            rows="5"
            spellcheck="false"
            class="mt-2 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest dark:border-brand-mist/20 dark:bg-zinc-900"
            placeholder="<!doctype html>…"
        ></textarea>
        @error('error_500_html') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
    </section>

    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        <label class="block" for="maintenance-html">
            <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Maintenance page') }}</span>
            <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Optional HTML when maintenance mode is on.') }}</span>
        </label>
        <textarea
            id="maintenance-html"
            wire:model="maintenance_html"
            rows="5"
            spellcheck="false"
            class="mt-2 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest dark:border-brand-mist/20 dark:bg-zinc-900"
            placeholder="<!doctype html>…"
        ></textarea>
        @error('maintenance_html') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
    </section>

    <div class="flex items-center justify-end gap-3 border-b border-brand-ink/10 bg-brand-sand/25 px-5 py-3 sm:px-6">
        <span wire:loading.inline-flex wire:target="save" class="inline-flex items-center gap-1.5 text-[11px] text-brand-moss">
            <x-spinner size="sm" variant="muted" />
            {{ __('Saving…') }}
        </span>
        @can('update', $site)
            <button
                type="button"
                wire:click="save"
                wire:loading.attr="disabled"
                wire:target="save"
                class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
            >
                {{ __('Save') }}
            </button>
        @endcan
    </div>

    <details class="group" @if ($hasRepoErrors) open @endif>
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 bg-brand-sand/10 px-5 py-3.5 text-sm font-semibold text-brand-ink hover:bg-brand-sand/20 sm:px-6 [&::-webkit-details-marker]:hidden">
            <span class="inline-flex items-center gap-2">
                {{ __('Advanced') }}
                @if ($hasRepoErrors)
                    <span class="rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                        {{ __('Repo') }}
                    </span>
                @endif
            </span>
            <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition group-open:rotate-180" />
        </summary>

        <div class="space-y-5 border-t border-brand-ink/10 px-5 py-4 sm:px-6">
            <div>
                <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Starters') }}</p>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Fill all three pages at once, then edit before Save.') }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($templates as $key => $template)
                        <button
                            type="button"
                            wire:click="applyAllTemplates('{{ $key }}')"
                            class="rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink hover:bg-brand-sand/40"
                            title="{{ $template['hint'] }}"
                        >
                            {{ $template['label'] }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('From :file', ['file' => $sourcePath]) }}</p>
                    <a
                        href="{{ route('sites.edge.dply-yaml', ['server' => $site->server_id, 'site' => $site->id]) }}"
                        class="inline-flex items-center gap-1 text-xs font-medium text-brand-sage hover:underline"
                    >
                        <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ __('Generate :file', ['file' => $sourcePath]) }}
                    </a>
                </div>
                @if ($hasRepoErrors)
                    <dl class="mt-2 grid grid-cols-1 gap-y-1.5 text-xs sm:grid-cols-[8rem_1fr]">
                        @if (! empty($repoErrors['html_404']))
                            <dt class="text-brand-mist">{{ __('404') }}</dt>
                            <dd class="text-brand-moss">{{ __('Set in repo') }}</dd>
                        @endif
                        @if (! empty($repoErrors['html_500']))
                            <dt class="text-brand-mist">{{ __('500') }}</dt>
                            <dd class="text-brand-moss">{{ __('Set in repo') }}</dd>
                        @endif
                        @if (! empty($repoMaint['enabled']))
                            <dt class="text-brand-mist">{{ __('Maintenance') }}</dt>
                            <dd class="text-brand-moss">{{ __('Enabled in repo') }}</dd>
                        @endif
                        @if (! empty($repoMaint['html']) || ! empty($repoMaint['html_path']))
                            <dt class="text-brand-mist">{{ __('Maintenance HTML') }}</dt>
                            <dd class="text-brand-moss">{{ __('Set in repo') }}</dd>
                        @endif
                    </dl>
                    <p class="mt-2 text-[11px] text-brand-mist">{{ __('Dashboard values override the repo when both are set.') }}</p>
                @else
                    <p class="mt-2 text-sm text-brand-moss">{{ __('None declared in :file yet.', ['file' => $sourcePath]) }}</p>
                @endif

                <x-edge-yaml-example :file="$sourcePath" :hint="__('Inline HTML or a path relative to the repo root.')">
error_pages:
  html_404: "<!doctype html><h1>Page not found</h1>"
  html_500_path: "public/500.html"

maintenance:
  enabled: false
  html_path: "public/maintenance.html"
                </x-edge-yaml-example>
            </div>
        </div>
    </details>
</div>

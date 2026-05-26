<div class="space-y-6">
    {{-- dply.yaml integration banner (same pattern as Firewall / Crons / Origin) --}}
    <section class="dply-card">
        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Error pages + maintenance') }}</h3>
                    <p class="mt-0.5 text-sm text-brand-moss">
                        {{ __('Customize 404 / 500 and the maintenance page. Declare them in :file with `error_pages:` / `maintenance:` blocks, or edit inline below — both round-trip into the live worker on the next deploy.', ['file' => $sourcePath]) }}
                    </p>
                </div>
                <a
                    href="{{ route('sites.edge.dply-yaml', ['server' => $site->server_id, 'site' => $site->id]) }}"
                    class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-ink hover:bg-brand-sand/40"
                >
                    <x-heroicon-o-arrow-down-tray class="h-3 w-3" aria-hidden="true" />
                    {{ __('Generate dply.yaml') }}
                </a>
            </div>
        </div>

        {{-- From dply.yaml --}}
        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
            <div class="flex items-baseline justify-between gap-2">
                <h4 class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('From :file', ['file' => $sourcePath]) }}</h4>
                <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Repo-managed') }}</span>
            </div>
            @if ($repoErrors !== [] || $repoMaint !== [])
                <dl class="mt-2 grid grid-cols-1 gap-y-2 rounded-lg border border-brand-ink/10 p-3 text-xs sm:grid-cols-[10rem_1fr]">
                    @if (! empty($repoErrors['html_404']))
                        <dt class="text-brand-mist">{{ __('404 HTML') }}</dt>
                        <dd class="text-brand-moss">{{ __(':bytes bytes declared in :file', ['bytes' => strlen((string) $repoErrors['html_404']), 'file' => $sourcePath]) }}</dd>
                    @endif
                    @if (! empty($repoErrors['html_500']))
                        <dt class="text-brand-mist">{{ __('500 HTML') }}</dt>
                        <dd class="text-brand-moss">{{ __(':bytes bytes declared in :file', ['bytes' => strlen((string) $repoErrors['html_500']), 'file' => $sourcePath]) }}</dd>
                    @endif
                    @if (! empty($repoMaint['enabled']))
                        <dt class="text-brand-mist">{{ __('Maintenance') }}</dt>
                        <dd class="text-brand-moss">{{ __('Enabled in :file', ['file' => $sourcePath]) }}</dd>
                    @endif
                    @if (! empty($repoMaint['html']))
                        <dt class="text-brand-mist">{{ __('Maintenance HTML') }}</dt>
                        <dd class="text-brand-moss">{{ __(':bytes bytes declared in :file', ['bytes' => strlen((string) $repoMaint['html']), 'file' => $sourcePath]) }}</dd>
                    @endif
                </dl>
            @else
                <p class="mt-2 text-sm text-brand-moss">
                    {{ __('Nothing declared in :file. Add an `error_pages:` / `maintenance:` block, or just edit inline below.', ['file' => $sourcePath]) }}
                </p>
                <pre class="mt-3 overflow-x-auto rounded-lg bg-brand-ink/95 px-4 py-3 font-mono text-[11px] leading-relaxed text-brand-sand"><code>error_pages:
  # Inline HTML directly...
  html_404: "&lt;!doctype html&gt;&lt;h1&gt;Page not found&lt;/h1&gt;"
  # ...or reference a file in your repo:
  html_500_path: "public/500.html"

maintenance:
  enabled: false
  html_path: "public/maintenance.html"</code></pre>
            @endif
            <p class="mt-2 text-[11px] text-brand-mist">{{ __('Dashboard edits override repo values when both are set — useful for flipping maintenance during an incident without a redeploy.') }}</p>
        </div>
    </section>

    {{-- Maintenance --}}
    <section class="dply-card" x-data="{ tpl: 'minimal' }">
        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
            <h3 class="inline-flex items-center gap-2 text-base font-semibold text-brand-ink">
                <x-heroicon-o-wrench class="h-4 w-4 text-brand-forest dark:text-brand-sage" aria-hidden="true" />
                {{ __('Maintenance mode') }}
            </h3>
            <p class="mt-0.5 text-sm text-brand-moss">
                {{ __('Take this site offline with a friendly 503 page. Toggling republishes the host map immediately — no redeploy needed.') }}
            </p>
        </div>
        <div class="space-y-4 px-6 py-5 sm:px-8">
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model.live="maintenance_enabled" class="mt-1 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                <span class="text-sm text-brand-ink">
                    <span class="font-semibold">{{ __('Enable maintenance mode') }}</span>
                    <span class="block text-xs text-brand-moss">{{ __('Visitors receive a 503 with Retry-After: 120. The vitals beacon stays open so tabs already loaded keep reporting.') }}</span>
                </span>
            </label>

            <div>
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <label class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist" for="maintenance-html">{{ __('Maintenance page HTML (optional)') }}</label>
                    <div class="flex flex-wrap items-center gap-1.5">
                        @foreach ($templates as $key => $template)
                            <button type="button" wire:click="applyTemplate('maintenance', '{{ $key }}')" class="rounded-lg border border-brand-ink/15 bg-white px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-ink hover:bg-brand-sand/40" title="{{ $template['hint'] }}">
                                {{ __('Use :label', ['label' => $template['label']]) }}
                            </button>
                        @endforeach
                    </div>
                </div>
                <textarea
                    id="maintenance-html"
                    wire:model="maintenance_html"
                    rows="8"
                    spellcheck="false"
                    class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest"
                    placeholder="<!doctype html><html>...</html>"
                ></textarea>
                @error('maintenance_html') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                <p class="mt-1 text-[11px] text-brand-mist">{{ __('Leave blank for the built-in default. Click a template button above to seed this field; you can edit before saving.') }}</p>
            </div>
        </div>
    </section>

    {{-- Custom 404 / 500 --}}
    <section class="dply-card">
        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
            <h3 class="inline-flex items-center gap-2 text-base font-semibold text-brand-ink">
                <x-heroicon-o-exclamation-circle class="h-4 w-4 text-brand-forest dark:text-brand-sage" aria-hidden="true" />
                {{ __('Custom error pages') }}
            </h3>
            <p class="mt-0.5 text-sm text-brand-moss">
                {{ __('Override the built-in 404 and 500 responses. Picked up by the worker on the next deploy.') }}
            </p>
        </div>
        <div class="space-y-5 px-6 py-5 sm:px-8">
            <div>
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <label class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist" for="error-404">{{ __('404 — Not Found HTML') }}</label>
                    <div class="flex flex-wrap items-center gap-1.5">
                        @foreach ($templates as $key => $template)
                            <button type="button" wire:click="applyTemplate('html_404', '{{ $key }}')" class="rounded-lg border border-brand-ink/15 bg-white px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-ink hover:bg-brand-sand/40" title="{{ $template['hint'] }}">
                                {{ __('Use :label', ['label' => $template['label']]) }}
                            </button>
                        @endforeach
                    </div>
                </div>
                <textarea
                    id="error-404"
                    wire:model="error_404_html"
                    rows="8"
                    spellcheck="false"
                    class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest"
                    placeholder="<!doctype html><html>...</html>"
                ></textarea>
                @error('error_404_html') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <label class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist" for="error-500">{{ __('500 — Internal Server Error HTML') }}</label>
                    <div class="flex flex-wrap items-center gap-1.5">
                        @foreach ($templates as $key => $template)
                            <button type="button" wire:click="applyTemplate('html_500', '{{ $key }}')" class="rounded-lg border border-brand-ink/15 bg-white px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-ink hover:bg-brand-sand/40" title="{{ $template['hint'] }}">
                                {{ __('Use :label', ['label' => $template['label']]) }}
                            </button>
                        @endforeach
                    </div>
                </div>
                <textarea
                    id="error-500"
                    wire:model="error_500_html"
                    rows="8"
                    spellcheck="false"
                    class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest"
                    placeholder="<!doctype html><html>...</html>"
                ></textarea>
                @error('error_500_html') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                <p class="mt-1 text-[11px] text-brand-mist">
                    {{ __('Hybrid sites with origin proxy: the existing "Origin failover" HTML is preferred for proxy errors. This 500 page covers static + unexpected worker faults.') }}
                </p>
            </div>
        </div>
        <div class="flex items-center justify-end gap-3 rounded-b-2xl border-t border-brand-ink/10 bg-brand-sand/20 px-6 py-3 sm:px-8">
            <span wire:loading.inline-flex wire:target="save" class="inline-flex items-center gap-1.5 text-[11px] text-brand-moss">
                <x-spinner size="sm" variant="muted" />
                {{ __('Saving…') }}
            </span>
            <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60">
                {{ __('Save') }}
            </button>
        </div>
    </section>
</div>

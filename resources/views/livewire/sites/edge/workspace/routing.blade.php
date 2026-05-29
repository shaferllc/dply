<div class="space-y-6">
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-arrows-right-left class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Routing') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Routing rules') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Redirects, rewrites, and header rules. Repo-declared rows come from :file; dashboard rows merge into the same list at deploy time and ship to the worker alongside.', ['file' => $sourcePath]) }}
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

        {{-- Repo-declared (read-only) --}}
        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
            <div class="flex items-baseline justify-between gap-2">
                <h4 class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('From :file', ['file' => $sourcePath]) }}</h4>
                <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Repo-managed') }}</span>
            </div>
            @if ($repoRedirects !== [] || $repoRewrites !== [] || $repoHeaders !== [])
                <div class="mt-2 grid grid-cols-1 gap-y-2 rounded-lg border border-brand-ink/10 p-3 text-xs sm:grid-cols-[8rem_1fr]">
                    @if ($repoRedirects !== [])
                        <dt class="text-brand-mist">{{ __('Redirects') }}</dt>
                        <dd class="text-brand-moss">{{ count($repoRedirects) }} {{ __('declared in') }} {{ $sourcePath }}</dd>
                    @endif
                    @if ($repoRewrites !== [])
                        <dt class="text-brand-mist">{{ __('Rewrites') }}</dt>
                        <dd class="text-brand-moss">{{ count($repoRewrites) }} {{ __('declared in') }} {{ $sourcePath }}</dd>
                    @endif
                    @if ($repoHeaders !== [])
                        <dt class="text-brand-mist">{{ __('Header rules') }}</dt>
                        <dd class="text-brand-moss">{{ count($repoHeaders) }} {{ __('declared in') }} {{ $sourcePath }}</dd>
                    @endif
                </div>
            @else
                <p class="mt-2 text-sm text-brand-moss">
                    {{ __('No routing in :file. Add `redirects:` / `rewrites:` / `headers:` blocks, or add rules below — dashboard rows merge in at deploy time.', ['file' => $sourcePath]) }}
                </p>
                <pre class="mt-3 overflow-x-auto rounded-lg bg-brand-ink/95 px-4 py-3 font-mono text-[11px] leading-relaxed text-brand-sand"><code>redirects:
  - from: /old-page
    to: /new-page
    status: 301

rewrites:
  - from: /api/*
    to: https://api.example.com/:splat

headers:
  - for: /assets/*
    values:
      Cache-Control: "public, max-age=31536000, immutable"</code></pre>
            @endif
        </div>

        {{-- Quick templates --}}
        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8" x-data="{ open: false }">
            <button type="button" @click="open = ! open" class="flex w-full items-center justify-between gap-3 text-left">
                <div>
                    <h4 class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Quick templates') }}</h4>
                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Apply a common rule set in one click, then prune what you don\'t need.') }}</p>
                </div>
                <x-heroicon-o-chevron-down class="h-4 w-4 text-brand-moss transition-transform" x-bind:class="open ? 'rotate-180' : ''" />
            </button>
            <div x-show="open" x-cloak class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                @foreach ($templates as $key => $template)
                    <div class="rounded-lg border border-brand-ink/10 p-3">
                        <div class="flex items-baseline justify-between gap-2">
                            <p class="text-sm font-semibold text-brand-ink">{{ $template['label'] }}</p>
                            <button type="button" wire:click="applyTemplate('{{ $key }}')" class="rounded-lg border border-brand-ink/15 bg-white px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-ink hover:bg-brand-sand/40">{{ __('Apply') }}</button>
                        </div>
                        <p class="mt-1 text-xs text-brand-moss">{{ $template['hint'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Dashboard redirects --}}
        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
            <div class="flex items-baseline justify-between gap-2">
                <h4 class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Redirects (dashboard-managed)') }}</h4>
                <span wire:loading.inline-flex wire:target="addRedirect,removeRedirect,applyTemplate" class="inline-flex items-center gap-1.5 text-[11px] text-brand-moss">
                    <x-spinner size="sm" variant="muted" />
                    {{ __('Saving…') }}
                </span>
            </div>

            @if ($dashboard_redirects === [])
                <p class="mt-2 text-xs text-brand-moss">{{ __('No dashboard redirects yet.') }}</p>
            @else
                <div class="mt-2 overflow-x-auto rounded-lg border border-brand-ink/10">
                    <table class="min-w-full divide-y divide-brand-ink/8 text-xs">
                        <thead class="bg-brand-sand/30 text-left text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                            <tr>
                                <th class="px-3 py-2">{{ __('From') }}</th>
                                <th class="px-3 py-2">{{ __('To') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('Status') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/8 text-brand-ink">
                            @foreach ($dashboard_redirects as $index => $rule)
                                <tr wire:key="dash-redirect-{{ $index }}">
                                    <td class="px-3 py-2 font-mono break-all">{{ $rule['from'] }}</td>
                                    <td class="px-3 py-2 font-mono break-all">{{ $rule['to'] }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ $rule['status'] }}</td>
                                    <td class="px-3 py-2 text-right"><button type="button" wire:click="removeRedirect({{ $index }})" wire:confirm="{{ __('Remove this redirect?') }}" class="text-xs font-semibold text-rose-600 hover:text-rose-700">{{ __('Remove') }}</button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <form wire:submit.prevent="addRedirect" class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-[2fr_2fr_6rem_auto] sm:items-end">
                <div>
                    <label for="new-redir-from" class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('From (path)') }}</label>
                    <input id="new-redir-from" type="text" wire:model="new_redirect_from" class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest" placeholder="/old-page" autocomplete="off" />
                    @error('new_redirect_from') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="new-redir-to" class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('To') }}</label>
                    <input id="new-redir-to" type="text" wire:model="new_redirect_to" class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest" placeholder="/new-page" autocomplete="off" />
                </div>
                <div>
                    <label for="new-redir-status" class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Status') }}</label>
                    <select id="new-redir-status" wire:model="new_redirect_status" class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-2 py-1.5 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest">
                        <option value="301">301</option>
                        <option value="302">302</option>
                        <option value="307">307</option>
                        <option value="308">308</option>
                    </select>
                </div>
                <button type="submit" class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90">{{ __('Add') }}</button>
            </form>
        </div>

        {{-- Dashboard rewrites --}}
        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
            <h4 class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Rewrites (dashboard-managed)') }}</h4>

            @if ($dashboard_rewrites === [])
                <p class="mt-2 text-xs text-brand-moss">{{ __('No dashboard rewrites yet.') }}</p>
            @else
                <div class="mt-2 overflow-x-auto rounded-lg border border-brand-ink/10">
                    <table class="min-w-full divide-y divide-brand-ink/8 text-xs">
                        <thead class="bg-brand-sand/30 text-left text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                            <tr>
                                <th class="px-3 py-2">{{ __('From') }}</th>
                                <th class="px-3 py-2">{{ __('To') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/8 text-brand-ink">
                            @foreach ($dashboard_rewrites as $index => $rule)
                                <tr wire:key="dash-rewrite-{{ $index }}">
                                    <td class="px-3 py-2 font-mono break-all">{{ $rule['from'] }}</td>
                                    <td class="px-3 py-2 font-mono break-all">{{ $rule['to'] }}</td>
                                    <td class="px-3 py-2 text-right"><button type="button" wire:click="removeRewrite({{ $index }})" wire:confirm="{{ __('Remove this rewrite?') }}" class="text-xs font-semibold text-rose-600 hover:text-rose-700">{{ __('Remove') }}</button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <form wire:submit.prevent="addRewrite" class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                <div>
                    <label for="new-rewrite-from" class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('From (path)') }}</label>
                    <input id="new-rewrite-from" type="text" wire:model="new_rewrite_from" class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest" placeholder="/api/*" autocomplete="off" />
                    @error('new_rewrite_from') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="new-rewrite-to" class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('To (path or absolute URL)') }}</label>
                    <input id="new-rewrite-to" type="text" wire:model="new_rewrite_to" class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest" placeholder="https://api.example.com/:splat" autocomplete="off" />
                </div>
                <button type="submit" class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90">{{ __('Add') }}</button>
            </form>
        </div>

        {{-- Dashboard headers --}}
        <div class="px-6 py-4 sm:px-8">
            <h4 class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Header rules (dashboard-managed)') }}</h4>

            @if ($dashboard_headers === [])
                <p class="mt-2 text-xs text-brand-moss">{{ __('No dashboard header rules yet.') }}</p>
            @else
                <ul class="mt-2 space-y-2">
                    @foreach ($dashboard_headers as $index => $rule)
                        <li wire:key="dash-headers-{{ $index }}" class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2 text-xs">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0 flex-1">
                                    <p class="font-mono text-brand-ink">{{ __('for') }} <span class="break-all">{{ $rule['for'] }}</span></p>
                                    <dl class="mt-1 grid grid-cols-1 gap-x-3 sm:grid-cols-[12rem_1fr]">
                                        @foreach ($rule['values'] as $name => $value)
                                            <dt class="font-mono text-brand-mist">{{ $name }}</dt>
                                            <dd class="font-mono text-brand-ink break-all">{{ $value }}</dd>
                                        @endforeach
                                    </dl>
                                </div>
                                <button type="button" wire:click="removeHeaderRule({{ $index }})" wire:confirm="{{ __('Remove this header rule?') }}" class="text-xs font-semibold text-rose-600 hover:text-rose-700">{{ __('Remove') }}</button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            <form wire:submit.prevent="addHeaderRule" class="mt-3 space-y-2">
                <div>
                    <label for="new-header-for" class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('For (path pattern)') }}</label>
                    <input id="new-header-for" type="text" wire:model="new_header_for" class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest" placeholder="/assets/*" autocomplete="off" />
                    @error('new_header_for') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="new-header-pairs" class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Headers (one per line, Name: value)') }}</label>
                    <textarea id="new-header-pairs" rows="4" wire:model="new_header_pairs" class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest" placeholder="Cache-Control: public, max-age=31536000, immutable&#10;X-Content-Type-Options: nosniff"></textarea>
                </div>
                <button type="submit" class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90">{{ __('Add header rule') }}</button>
            </form>
        </div>
    </section>
</div>

@php
    $kindLabels = [
        'kv' => __('KV namespace'),
        'r2' => __('R2 bucket'),
        'd1' => __('D1 database'),
        'queue' => __('Queue'),
    ];
    $valueLabels = [
        'kv' => __('Namespace ID'),
        'r2' => __('Bucket name'),
        'd1' => __('Database ID'),
        'queue' => __('Queue name'),
    ];
@endphp

<div class="space-y-6">
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-puzzle-piece class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Bindings') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Cloudflare bindings') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Resources your worker reaches through `env.NAME`. Repo-declared rows come from wrangler.toml; dashboard rows merge into the same list at deploy time and ship to Cloudflare alongside.') }}
                </p>
            </div>
        </div>

        @unless ($hasWorker)
            <div class="border-b border-brand-ink/10 bg-amber-50/60 px-6 py-4 sm:px-8">
                <div class="flex items-start gap-2.5">
                    <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" aria-hidden="true" />
                    <p class="text-xs leading-relaxed text-amber-900">
                        {{ __('This site serves static assets straight from R2 and has no Worker of its own, so bindings added here will not be reachable yet. Add a `middleware.ts` to the repo (or switch the site to SSR) and redeploy — the bindings below attach to that Worker automatically once it exists.') }}
                    </p>
                </div>
            </div>
        @endunless

        {{-- Repo-declared bindings (read-only, source of truth) --}}
        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
            <div class="flex items-baseline justify-between gap-2">
                <h4 class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('From wrangler.toml') }}</h4>
                <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                    {{ __('Repo-managed') }}
                </span>
            </div>
            @if ($repoBindings !== [])
                <div class="mt-2 overflow-x-auto rounded-lg border border-brand-ink/10">
                    <table class="min-w-full divide-y divide-brand-ink/8 text-xs">
                        <thead class="bg-brand-sand/30 text-left text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                            <tr>
                                <th class="px-3 py-2">{{ __('Name') }}</th>
                                <th class="px-3 py-2">{{ __('Type') }}</th>
                                <th class="px-3 py-2">{{ __('Resource') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/8 text-brand-ink">
                            @foreach ($repoBindings as $entry)
                                <tr>
                                    <td class="px-3 py-2 font-mono">env.{{ $entry['name'] }}</td>
                                    <td class="px-3 py-2 text-brand-moss">{{ $kindLabels[$entry['kind']] ?? $entry['kind'] }}</td>
                                    <td class="px-3 py-2 font-mono text-brand-moss">{{ $entry['value'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="mt-2 text-sm text-brand-moss">
                    {{ __('No deploy has shipped a wrangler.toml with bindings yet. Declare them in the repo and redeploy, or add rows below and we\'ll inject them on the next deploy.') }}
                </p>
                <pre class="mt-3 overflow-x-auto rounded-lg bg-brand-ink/95 px-4 py-3 font-mono text-[11px] leading-relaxed text-brand-sand"><code>[[kv_namespaces]]
binding = "SESSIONS"
id = "abc123..."

[[r2_buckets]]
binding = "UPLOADS"
bucket_name = "my-uploads"</code></pre>
            @endif
        </div>

        {{-- Dashboard-managed bindings (editable) --}}
        <div class="px-6 py-4 sm:px-8">
            <div class="flex items-center justify-between gap-2">
                <h4 class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Dashboard-managed') }}</h4>
                <span wire:loading.inline-flex wire:target="addBinding,removeBinding" class="inline-flex items-center gap-1.5 text-[11px] text-brand-moss">
                    <x-spinner size="sm" variant="muted" />
                    {{ __('Saving…') }}
                </span>
            </div>

            @if ($dashboard_bindings === [])
                <p class="mt-2 text-xs text-brand-moss">{{ __('No dashboard bindings yet — add one below or declare them in wrangler.toml.') }}</p>
            @else
                <div class="mt-2 overflow-x-auto rounded-lg border border-brand-ink/10">
                    <table class="min-w-full divide-y divide-brand-ink/8 text-xs">
                        <thead class="bg-brand-sand/30 text-left text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                            <tr>
                                <th class="px-3 py-2">{{ __('Name') }}</th>
                                <th class="px-3 py-2">{{ __('Type') }}</th>
                                <th class="px-3 py-2">{{ __('Resource') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/8 text-brand-ink">
                            @foreach ($dashboard_bindings as $index => $entry)
                                <tr wire:key="binding-{{ $index }}-{{ $entry['name'] }}">
                                    <td class="px-3 py-2 font-mono">env.{{ $entry['name'] }}</td>
                                    <td class="px-3 py-2 text-brand-moss">{{ $kindLabels[$entry['kind']] ?? $entry['kind'] }}</td>
                                    <td class="px-3 py-2 font-mono text-brand-moss">{{ $entry['value'] }}</td>
                                    <td class="px-3 py-2 text-right">
                                        <button
                                            type="button"
                                            wire:click="removeBinding({{ $index }})"
                                            wire:confirm="{{ __('Detach this binding? The Cloudflare resource and its data are left in place.') }}"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-rose-700 shadow-sm hover:bg-rose-50"
                                        >
                                            {{ __('Detach') }}
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <form wire:submit.prevent="addBinding" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-[1fr_1fr_1.4fr_auto] sm:items-end">
                <div>
                    <label for="new-binding-name" class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Name') }}</label>
                    <input
                        id="new-binding-name"
                        type="text"
                        wire:model="new_name"
                        class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest"
                        placeholder="SESSIONS"
                        autocomplete="off"
                    />
                    @error('new_name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="new-binding-kind" class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Type') }}</label>
                    <select
                        id="new-binding-kind"
                        wire:model.live="new_kind"
                        class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest"
                    >
                        @foreach ($kinds as $kind)
                            <option value="{{ $kind }}">{{ $kindLabels[$kind] ?? $kind }}</option>
                        @endforeach
                    </select>
                    @error('new_kind') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="new-binding-value" class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                        {{ $create_resource ? __('New resource name') : ($valueLabels[$new_kind] ?? __('Identifier')) }}
                    </label>
                    <input
                        id="new-binding-value"
                        type="text"
                        wire:model="new_value"
                        class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest"
                        placeholder="{{ $create_resource ? 'my-new-resource' : ($valueLabels[$new_kind] ?? '') }}"
                        autocomplete="off"
                    />
                    @error('new_value') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <button type="submit" class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90">
                        {{ $create_resource ? __('Create & attach') : __('Attach') }}
                    </button>
                </div>
            </form>

            <label class="mt-3 inline-flex items-center gap-2 text-[11px] text-brand-moss">
                <input type="checkbox" wire:model.live="create_resource" class="rounded border-brand-ink/20 text-brand-forest focus:ring-brand-forest" />
                {{ __('Create the resource in Cloudflare instead of attaching an existing one') }}
            </label>

            <p class="mt-3 text-[11px] text-brand-mist">
                {{ __('Bindings apply on the next deploy. Detaching removes the binding from your worker but never deletes the Cloudflare resource or its data. Names collide in favour of wrangler.toml.') }}
            </p>
        </div>
    </section>
</div>

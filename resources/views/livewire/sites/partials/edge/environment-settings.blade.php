@if (! $site->isEdgePreview())
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Secrets') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Environment variables') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Production-scope secrets for builds and runtime. Encrypted at rest, injected into the build container at deploy time, and exposed as secret_text bindings on middleware / SSR workers.') }}</p>
            </div>
        </div>

        @can('update', $site)
            <form wire:submit.prevent="saveEdgeEnvVar" class="grid gap-2 border-b border-brand-ink/10 px-6 py-4 sm:grid-cols-[minmax(10rem,1fr)_minmax(14rem,2fr)_auto] sm:px-8">
                <label class="block">
                    <span class="block text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Key') }}</span>
                    <input type="text"
                           wire:model="edge_env_var_key"
                           placeholder="DATABASE_URL"
                           class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm uppercase text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900" />
                    @error('edge_env_var_key') <p class="mt-1 text-[11px] text-rose-700">{{ $message }}</p> @enderror
                </label>
                <label class="block">
                    <span class="block text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Value') }}</span>
                    <input type="password"
                           wire:model="edge_env_var_value"
                           autocomplete="off"
                           class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900" />
                </label>
                <button type="submit" wire:loading.attr="disabled" wire:target="saveEdgeEnvVar" class="self-end rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60">
                    {{ __('Set value') }}
                </button>
            </form>
        @endcan

        @php
            $envKeys = $this->edgeEnvVarKeys();
        @endphp
        @if ($envKeys === [])
            <div class="px-6 py-6 text-center text-xs text-brand-moss sm:px-8">{{ __('No env vars set.') }}</div>
        @else
            <ul class="divide-y divide-brand-ink/8">
                @foreach ($envKeys as $envRow)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-6 py-3 sm:px-8" wire:key="edge-env-{{ $envRow['key'] }}">
                        <div class="min-w-0">
                            <p class="font-mono text-sm text-brand-ink">{{ $envRow['key'] }}</p>
                            <p class="mt-0.5 text-[11px] text-brand-moss">
                                @if ($envRow['updated_at'])
                                    {{ __('updated :when', ['when' => $envRow['updated_at']]) }}
                                @else
                                    {{ __('encrypted at rest · value is write-only') }}
                                @endif
                            </p>
                        </div>
                        @can('update', $site)
                            <button
                                type="button"
                                wire:click="removeEdgeEnvVar('{{ $envRow['key'] }}')"
                                wire:confirm="{{ __('Remove :key? It will be missing from the next deploy.', ['key' => $envRow['key']]) }}"
                                class="text-xs font-medium text-rose-700 hover:text-rose-900 dark:text-rose-400">
                                {{ __('Remove') }}
                            </button>
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@else
    <section class="dply-card overflow-hidden px-6 py-8 text-center text-sm text-brand-moss sm:px-8">
        <p>{{ __('Environment variables are managed on the parent Edge site.') }}</p>
    </section>
@endif

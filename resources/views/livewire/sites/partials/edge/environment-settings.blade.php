{{-- Primary Environment strip: set / list secrets. Repo-managed env lives under Advanced on the parent page. --}}
<section class="border-b border-brand-ink/10">
    @can('update', $site)
        <form wire:submit.prevent="saveEdgeEnvVar" class="grid gap-3 border-b border-brand-ink/10 px-5 py-4 sm:grid-cols-[minmax(10rem,1fr)_minmax(14rem,2fr)_auto] sm:items-end sm:px-6">
            <label class="block">
                <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Key') }}</span>
                <input
                    type="text"
                    wire:model="edge_env_var_key"
                    placeholder="DATABASE_URL"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm uppercase text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                />
                @error('edge_env_var_key')
                    <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                @enderror
            </label>
            <label class="block">
                <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Value') }}</span>
                <input
                    type="password"
                    wire:model="edge_env_var_value"
                    autocomplete="off"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                />
            </label>
            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="saveEdgeEnvVar"
                class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="saveEdgeEnvVar">{{ __('Set') }}</span>
                <span wire:loading wire:target="saveEdgeEnvVar">{{ __('Saving…') }}</span>
            </button>
        </form>
    @endcan

    @php
        $envKeys = $this->edgeEnvVarKeys();
    @endphp
    @if ($envKeys === [])
        <div class="px-5 py-6 text-center text-sm text-brand-moss sm:px-6">
            {{ __('No env vars set yet.') }}
            <p class="mt-1 text-xs">{{ __('Encrypted at rest · write-only values · redeploy to apply.') }}</p>
        </div>
    @else
        <ul class="divide-y divide-brand-ink/8">
            @foreach ($envKeys as $envRow)
                <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 sm:px-6" wire:key="edge-env-{{ $envRow['key'] }}">
                    <div class="min-w-0">
                        <p class="font-mono text-sm text-brand-ink">{{ $envRow['key'] }}</p>
                        <p class="mt-0.5 text-xs text-brand-moss">
                            @if ($envRow['updated_at'])
                                {{ __('updated :when', ['when' => $envRow['updated_at']]) }}
                            @else
                                {{ __('encrypted · write-only') }}
                            @endif
                        </p>
                    </div>
                    @can('update', $site)
                        <button
                            type="button"
                            wire:click="openConfirmActionModal('removeEdgeEnvVar', @js([$envRow['key']]), @js(__('Remove env var')), @js(__('Remove :key? It will be missing from the next deploy.', ['key' => $envRow['key']])), @js(__('Remove')), true)"
                            class="text-xs font-medium text-rose-700 hover:text-rose-900 dark:text-rose-400"
                        >
                            {{ __('Remove') }}
                        </button>
                    @endcan
                </li>
            @endforeach
        </ul>
    @endif
</section>

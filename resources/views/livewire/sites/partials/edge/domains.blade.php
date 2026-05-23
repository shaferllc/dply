<section class="dply-card overflow-hidden">
    <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
        <h3 class="text-base font-semibold text-brand-ink">{{ __('Default hostname') }}</h3>
        <p class="mt-0.5 text-sm text-brand-moss">{{ __('Your site is always available on its dply Edge URL.') }}</p>
    </div>
    <div class="px-6 py-4 sm:px-8">
        @if ($edgeLiveUrl)
            <p class="font-mono text-sm text-brand-ink break-all">{{ $edgeLiveUrl }}</p>
        @else
            <p class="text-sm text-brand-moss">{{ __('Pending first deploy') }}</p>
        @endif
    </div>
</section>

<section class="dply-card overflow-hidden">
    <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
        <h3 class="text-base font-semibold text-brand-ink">{{ __('Custom domains') }}</h3>
        <p class="mt-0.5 text-sm text-brand-moss">{{ __('Point DNS at dply Edge, then attach the hostname here. TLS is handled automatically.') }}</p>
    </div>
    <div class="space-y-4 px-6 py-5 sm:px-8">
        @if ($edgeAttachedDomains !== [])
            <ul class="divide-y divide-brand-ink/8 rounded-xl border border-brand-ink/10">
                @foreach ($edgeAttachedDomains as $hostname => $info)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                        <div class="min-w-0">
                            <p class="font-mono text-sm text-brand-ink">{{ $hostname }}</p>
                            @if (is_array($info) && ! empty($info['attached_at']))
                                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Attached :time', ['time' => $info['attached_at']]) }}</p>
                            @endif
                        </div>
                        @can('update', $site)
                            <button type="button" wire:click="detachEdgeDomain('{{ $hostname }}')" class="text-xs font-medium text-rose-700 hover:text-rose-900 dark:text-rose-400">
                                {{ __('Remove') }}
                            </button>
                        @endcan
                    </li>
                @endforeach
            </ul>
        @else
            <p class="text-sm text-brand-moss">{{ __('No custom domains yet.') }}</p>
        @endif

        @can('update', $site)
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="min-w-0 flex-1">
                    <x-input-label for="edge_domain_input" :value="__('Hostname')" />
                    <x-text-input id="edge_domain_input" type="text" wire:model="edge_domain_input" class="mt-1.5 block w-full font-mono" placeholder="www.example.com" />
                </div>
                <x-primary-button type="button" wire:click="attachEdgeDomain" wire:loading.attr="disabled" wire:target="attachEdgeDomain" class="shrink-0">
                    <span wire:loading.remove wire:target="attachEdgeDomain">{{ __('Attach domain') }}</span>
                    <span wire:loading wire:target="attachEdgeDomain">{{ __('Attaching…') }}</span>
                </x-primary-button>
            </div>
        @endcan
    </div>
</section>

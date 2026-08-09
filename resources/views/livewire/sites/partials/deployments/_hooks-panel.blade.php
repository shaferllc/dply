<section id="hooks" class="scroll-mt-24">
    @if ($site->server?->isDigitalOceanFunctionsHost())
        <livewire:sites.deploy-hooks
            :site="$site"
            wire:key="deployments-hooks-{{ $site->id }}"
        />
    @else
        <div class="px-3 py-4 text-center text-xs text-brand-moss sm:px-4">
            {{ __('Deploy hooks are only available on DigitalOcean Functions hosts.') }}
        </div>
    @endif
</section>

<section id="hooks" class="scroll-mt-24">
    @if ($site->server?->isDigitalOceanFunctionsHost())
        <livewire:sites.deploy-hooks
            :site="$site"
            wire:key="deployments-hooks-{{ $site->id }}"
        />
    @else
        <div class="rounded-2xl border border-dashed border-brand-ink/15 bg-brand-sand/20 p-8 text-center text-sm text-brand-moss">
            {{ __('Deploy hooks are only available on DigitalOcean Functions hosts.') }}
        </div>
    @endif
</section>

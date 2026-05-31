<section class="dply-card overflow-hidden">
    <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <p class="text-sm leading-relaxed text-brand-moss">{{ __('Summary of how this site deploys. Edit each area from the tabs above.') }}</p>
    </div>
    <dl class="grid gap-4 px-6 py-6 sm:grid-cols-2 sm:px-8">
        <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/40 p-4">
            <dt class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Strategy') }}</dt>
            <dd class="mt-1 text-sm font-semibold text-brand-ink">{{ $site->deploy_strategy === 'atomic' ? __('Zero downtime (atomic)') : __('Simple (in-place)') }}</dd>
        </div>
        <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/40 p-4">
            <dt class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Pipeline steps') }}</dt>
            <dd class="mt-1 text-sm font-semibold text-brand-ink">{{ trans_choice('{0} None|{1} :count step|[2,*] :count steps', $site->deploySteps->count(), ['count' => $site->deploySteps->count()]) }}</dd>
        </div>
        <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/40 p-4">
            <dt class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Deploy hooks') }}</dt>
            <dd class="mt-1 text-sm font-semibold text-brand-ink">{{ trans_choice('{0} None|{1} :count hook|[2,*] :count hooks', $site->deployHooks->count(), ['count' => $site->deployHooks->count()]) }}</dd>
        </div>
        <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/40 p-4">
            <dt class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Post-deploy health check') }}</dt>
            <dd class="mt-1 text-sm font-semibold text-brand-ink">
                @if ($deploy_health_enabled && $zero_downtime_enabled)
                    {{ __('Enabled') }} — {{ $deploy_health_scheme }}://{{ $deploy_health_host }}{{ $deploy_health_path }}
                @else
                    {{ __('Off') }}
                @endif
            </dd>
        </div>
    </dl>
    <div class="flex flex-wrap gap-2 border-t border-brand-ink/10 bg-brand-sand/20 px-6 py-4 sm:px-8">
        <button type="button" wire:click="setPipelineTab('steps')" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">{{ __('Edit build steps') }}</button>
        <button type="button" wire:click="setPipelineTab('steps')" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">{{ __('Edit hooks') }}</button>
        <button type="button" wire:click="setPipelineTab('rollout')" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">{{ __('Edit rollout') }}</button>
    </div>
</section>

@if ($site->usesDockerRuntime() || $site->usesKubernetesRuntime())
    @include('livewire.sites.partials.pipeline._runtime-artifacts')
@endif

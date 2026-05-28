<div class="dply-page-shell py-8">
    <x-page-header
        :eyebrow="__('Sites')"
        :title="__('Promote to server')"
        :description="__('Copy this VM site to a standby server on a preview hostname first — smoke-test, then cut over production DNS when ready.')"
        doc-route="docs.index"
        flush
        compact
    />
    <p class="mb-8 text-sm text-brand-moss">
        <a href="{{ route('sites.show', [$server, $site]) }}" wire:navigate class="font-medium text-brand-forest hover:underline">{{ $site->name }}</a>
        <span class="text-brand-moss">·</span>
        {{ $server->name }}
        @if ($sourceProductionHostname !== '')
            <span class="text-brand-moss">·</span>
            <span class="font-mono text-xs">{{ $sourceProductionHostname }}</span>
        @endif
    </p>

    <div class="grid gap-8 lg:grid-cols-2">
        <div class="space-y-4 rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-brand-ink">{{ __('Preview-first promote') }}</h2>
            <p class="text-sm leading-6 text-brand-moss">
                {{ __('Unlike a straight clone, promote defaults to a managed preview hostname on the destination server so you can validate deploys before touching production DNS.') }}
            </p>
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.12em] text-brand-sage">{{ __('Included') }}</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-brand-moss">
                    <li>{{ __('Git remote, branch, deploy strategy, hooks, pipeline steps, and redirects.') }}</li>
                    <li>{{ __('Site-scoped server cron rows copied to the destination (sync on next cron apply).') }}</li>
                    <li>{{ __('Repository tree copy for VM sites, then normal provisioning.') }}</li>
                    <li>{{ __('Cutover playbook on the standby site after provisioning.') }}</li>
                </ul>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.12em] text-brand-sage">{{ __('Still manual') }}</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-brand-moss">
                    <li>{{ __('Environment variables and secrets — copy from source settings.') }}</li>
                    <li>{{ __('Databases and TLS certificates.') }}</li>
                    <li>{{ __('Production DNS cutover — follow the playbook when preview looks good.') }}</li>
                </ul>
            </div>

            @if (count($cutoverPreview) > 0)
                <div class="rounded-xl border border-brand-sage/25 bg-brand-sage/5 p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-forest">{{ __('Cutover playbook preview') }}</p>
                    <ol class="mt-3 space-y-2 text-sm text-brand-ink">
                        @foreach ($cutoverPreview as $index => $step)
                            <li class="flex gap-2">
                                <span class="shrink-0 font-semibold text-brand-moss">{{ $index + 1 }}.</span>
                                <span>{{ $step['text'] }}</span>
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endif
        </div>

        <form wire:submit="startPromote" class="space-y-5 rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm">
            <div>
                <x-input-label for="destination_server_id" :value="__('Destination server')" />
                <select id="destination_server_id" wire:model.live="destination_server_id" class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink">
                    <option value="">{{ __('Choose a standby server…') }}</option>
                    @foreach ($destinationServers as $s)
                        <option value="{{ $s->id }}">{{ $s->name }} ({{ $s->ip_address }})</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('destination_server_id')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="promote_site_name" :value="__('Standby site name')" />
                <x-text-input id="promote_site_name" wire:model="promote_site_name" class="mt-1 block w-full text-sm" />
                <x-input-error :messages="$errors->get('promote_site_name')" class="mt-1" />
            </div>

            <fieldset class="space-y-3">
                <legend class="text-sm font-semibold text-brand-ink">{{ __('Primary hostname on standby') }}</legend>
                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-brand-ink/10 p-3">
                    <input type="radio" wire:model.live="hostname_mode" value="preview" class="mt-1" />
                    <span>
                        <span class="block text-sm font-medium text-brand-ink">{{ __('Managed preview hostname (recommended)') }}</span>
                        <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Uses the unified on-dply.* testing pool pattern — safe for smoke tests before cutover.') }}</span>
                        @if ($previewHostname)
                            <span class="mt-2 inline-block font-mono text-xs text-brand-forest">{{ $previewHostname }}</span>
                        @endif
                    </span>
                </label>
                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-brand-ink/10 p-3">
                    <input type="radio" wire:model.live="hostname_mode" value="custom" class="mt-1" />
                    <span class="flex-1">
                        <span class="block text-sm font-medium text-brand-ink">{{ __('Custom hostname') }}</span>
                        <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Pick your own unused domain if you already have a staging hostname ready.') }}</span>
                        @if ($hostname_mode === 'custom')
                            <x-text-input wire:model="custom_hostname" class="mt-2 block w-full font-mono text-sm" placeholder="staging.example.com" />
                            <x-input-error :messages="$errors->get('custom_hostname')" class="mt-1" />
                        @endif
                    </span>
                </label>
            </fieldset>

            <div class="flex flex-wrap gap-3 pt-2">
                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="startPromote">
                    <span wire:loading.remove wire:target="startPromote">{{ __('Start promote') }}</span>
                    <span wire:loading wire:target="startPromote" class="inline-flex items-center gap-2">
                        <x-spinner variant="cream" class="h-4 w-4" />
                        {{ __('Queueing…') }}
                    </span>
                </x-primary-button>
                <a href="{{ route('sites.show', [$server, $site, 'section' => 'danger']) }}" wire:navigate class="inline-flex items-center rounded-xl border border-brand-ink/15 px-4 py-2.5 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">
                    {{ __('Cancel') }}
                </a>
            </div>
        </form>
    </div>
</div>

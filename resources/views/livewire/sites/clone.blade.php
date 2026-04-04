<div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
    <div class="mb-6">
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Sites') }}</p>
        <h1 class="mt-2 text-2xl font-semibold text-brand-ink">{{ __('Clone site') }}</h1>
        <p class="mt-1 text-sm text-brand-moss">
            <a href="{{ route('sites.show', [$server, $site]) }}" wire:navigate class="font-medium text-brand-forest hover:underline">{{ $site->name }}</a>
            <span class="text-brand-moss">·</span>
            {{ $server->name }}
        </p>
    </div>

    <div class="grid gap-8 lg:grid-cols-2">
        <div class="space-y-4 rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm">
            <h2 class="text-sm font-semibold text-brand-ink">{{ __('What happens') }}</h2>
            <p class="text-sm leading-6 text-brand-moss">
                {{ __('You can clone this site to another server in your organization, or to the same server with a new domain. The job runs in the background; time depends on site size.') }}
            </p>
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.12em] text-brand-sage">{{ __('Included') }}</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-brand-moss">
                    <li>{{ __('New site record and primary domain on the destination server.') }}</li>
                    <li>{{ __('For VM (SSH) sites: copy of the repository tree, then ownership aligned to the effective system user, then normal provisioning.') }}</li>
                    <li>{{ __('For serverless or container runtimes: copy of deploy settings and metadata; provisioning runs without cloning arbitrary remote volumes.') }}</li>
                    <li>{{ __('Deploy hooks and pipeline steps (duplicated to the new site).') }}</li>
                </ul>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.12em] text-brand-sage">{{ __('Not included') }}</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-brand-moss">
                    <li>{{ __('Databases are not copied.') }}</li>
                    <li>{{ __('SSL certificates are not copied; issue new certificates on the new site.') }}</li>
                    <li>{{ __('Environment files and secrets are not copied; configure the new site’s environment separately.') }}</li>
                    <li>{{ __('Custom Nginx “extra” snippets are not copied; managed vhost is regenerated.') }}</li>
                </ul>
            </div>
        </div>

        <form wire:submit="startClone" class="space-y-5 rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm">
            <div>
                <x-input-label for="clone_hostname" :value="__('Domain')" />
                <x-text-input id="clone_hostname" wire:model="clone_hostname" class="mt-1 block w-full font-mono text-sm" placeholder="app.example.com" />
                <p class="mt-1 text-xs text-brand-moss">{{ __('Primary hostname for the new site. Change it if you are cloning to a different domain.') }}</p>
                <x-input-error :messages="$errors->get('clone_hostname')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="clone_site_name" :value="__('Site name')" />
                <x-text-input id="clone_site_name" wire:model="clone_site_name" class="mt-1 block w-full text-sm" />
                <x-input-error :messages="$errors->get('clone_site_name')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="destination_server_id" :value="__('Destination server')" />
                <select id="destination_server_id" wire:model="destination_server_id" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm">
                    <option value="">{{ __('Choose a server…') }}</option>
                    @foreach ($destinationServers as $s)
                        <option value="{{ $s->id }}">{{ $s->name }} ({{ $s->ip_address }})</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('destination_server_id')" class="mt-1" />
            </div>

            <div class="flex flex-wrap gap-3 pt-2">
                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="startClone">
                    <span wire:loading.remove wire:target="startClone">{{ __('Start clone') }}</span>
                    <span wire:loading wire:target="startClone" class="inline-flex items-center gap-2">
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

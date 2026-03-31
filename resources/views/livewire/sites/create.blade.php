@php
    $card = 'rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden';
@endphp

<x-server-workspace-shell :server="$server" active="sites">
    <nav class="text-sm text-brand-moss mb-6" aria-label="{{ __('Breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-2">
            <li><a href="{{ route('dashboard') }}" wire:navigate class="hover:text-brand-ink transition-colors">{{ __('Dashboard') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('servers.index') }}" wire:navigate class="hover:text-brand-ink transition-colors">{{ __('Servers') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li>
                <a href="{{ route('servers.sites', $server) }}" wire:navigate class="hover:text-brand-ink transition-colors truncate max-w-[10rem] sm:max-w-none">{{ $server->name }}</a>
            </li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li class="text-brand-ink font-medium">{{ __('New site') }}</li>
        </ol>
    </nav>

    <header class="mb-8 pb-6 border-b border-brand-ink/10">
        <h1 class="text-2xl font-bold tracking-tight text-brand-ink">{{ __('New site') }}</h1>
        <p class="mt-1 text-sm text-brand-moss">{{ __('Create a site on :server. Point DNS at this server before going live.', ['server' => $server->name]) }}</p>
    </header>

    <div class="space-y-8">
        {{-- Server snapshot (same facts as server overview) --}}
        <div class="{{ $card }} p-6 sm:p-8">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Server') }}</h2>
            <p class="mt-1 text-sm text-brand-moss leading-relaxed">{{ __('You are provisioning against this machine. Copy SSH or IP if you need them for DNS or firewall checks.') }}</p>
            <dl class="mt-6 grid grid-cols-1 gap-6 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-brand-moss">{{ __('Status') }}</dt>
                    <dd class="mt-1 font-medium text-brand-ink">{{ $server->status }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-brand-moss">{{ __('Provider') }}</dt>
                    <dd class="mt-1 font-medium text-brand-ink">{{ $server->provider->label() }}</dd>
                </div>
                @if ($server->setup_script_key)
                    <div>
                        <dt class="text-sm text-brand-moss">{{ __('Setup script') }}</dt>
                        <dd class="mt-1 font-medium text-brand-ink">{{ config("setup_scripts.scripts.{$server->setup_script_key}.name", $server->setup_script_key) }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-brand-moss">{{ __('Setup status') }}</dt>
                        <dd class="mt-1 font-medium">
                            @if ($server->setup_status === 'done')
                                <span class="text-brand-forest">{{ __('Done') }}</span>
                            @elseif ($server->setup_status === 'failed')
                                <span class="text-red-700">{{ __('Failed') }}</span>
                            @elseif ($server->setup_status === 'running')
                                <span class="text-brand-copper">{{ __('Running') }}</span>
                            @else
                                <span class="text-brand-mist">{{ $server->setup_status ?? __('Pending') }}</span>
                            @endif
                        </dd>
                    </div>
                @endif
                <div>
                    <dt class="text-sm text-brand-moss">{{ __('IP address') }}</dt>
                    <dd class="mt-1 font-mono text-sm font-medium text-brand-ink">{{ $server->ip_address ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-brand-moss">{{ __('SSH user') }}</dt>
                    <dd class="mt-1 font-mono text-sm font-medium text-brand-ink">{{ $server->ssh_user }}</dd>
                </div>
                @if ($server->status === 'ready')
                    <div>
                        <dt class="text-sm text-brand-moss">{{ __('Health') }}</dt>
                        <dd class="mt-1 font-medium">
                            @if ($server->health_status === 'reachable')
                                <span class="text-brand-forest">{{ __('Reachable') }}</span>
                            @elseif ($server->health_status === 'unreachable')
                                <span class="text-red-700">{{ __('Unreachable') }}</span>
                            @else
                                <span class="text-brand-mist">—</span>
                            @endif
                            @if ($server->last_health_check_at)
                                <span class="text-sm font-normal text-brand-mist">({{ $server->last_health_check_at->diffForHumans() }})</span>
                            @endif
                        </dd>
                    </div>
                @endif
                <div>
                    <dt class="text-sm text-brand-moss">{{ __('Sites on server') }}</dt>
                    <dd class="mt-1 font-medium text-brand-ink">{{ number_format($server->sites_count) }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-sm text-brand-moss">{{ __('SSH command') }}</dt>
                    <dd class="mt-1 break-all font-mono text-xs leading-relaxed text-brand-ink">{{ $server->getSshConnectionString() }}</dd>
                </div>
            </dl>
        </div>

        <div class="{{ $card }} p-6 sm:p-8">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Site details') }}</h2>
            <p class="mt-1 text-sm text-brand-moss leading-relaxed">{{ __('Primary domain must be unique. DNS should resolve to the server IP above before you expect SSL to succeed.') }}</p>

            <form wire:submit="store" class="mt-8 space-y-6">
                <div>
                    <x-input-label for="name" :value="__('Site name')" />
                    <x-text-input id="name" wire:model="form.name" class="mt-1 block w-full" required autofocus autocomplete="off" />
                    <x-input-error :messages="$errors->get('form.name')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="primary_hostname" :value="__('Primary domain (DNS must point to this server)')" />
                    <x-text-input id="primary_hostname" wire:model="form.primary_hostname" placeholder="app.example.com" class="mt-1 block w-full font-mono text-sm" required autocomplete="off" />
                    <x-input-error :messages="$errors->get('form.primary_hostname')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="type" :value="__('Stack')" />
                    <select id="type" wire:model.live="form.type" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30">
                        <option value="php">{{ __('PHP (PHP-FPM + Nginx)') }}</option>
                        <option value="static">{{ __('Static files') }}</option>
                        <option value="node">{{ __('Node (Nginx → reverse proxy)') }}</option>
                    </select>
                </div>
                <div>
                    <x-input-label for="document_root" :value="__('Document root (on server)')" />
                    <x-text-input id="document_root" wire:model="form.document_root" class="mt-1 block w-full font-mono text-sm" required />
                    <p class="mt-2 text-sm text-brand-moss">{{ __('For Laravel use the') }} <code class="rounded bg-brand-sand/60 px-1 py-0.5 text-xs text-brand-ink">public</code> {{ __('directory.') }}</p>
                    <x-input-error :messages="$errors->get('form.document_root')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="repository_path" :value="__('Git / deploy path (optional)')" />
                    <x-text-input id="repository_path" wire:model="form.repository_path" class="mt-1 block w-full font-mono text-sm" />
                    <p class="mt-2 text-sm text-brand-moss">{{ __('Where') }} <code class="rounded bg-brand-sand/60 px-1 py-0.5 text-xs text-brand-ink">git pull</code> {{ __('runs; defaults to document root if empty.') }}</p>
                </div>
                @if ($form->type === 'php')
                    <div>
                        <x-input-label for="php_version" :value="__('PHP-FPM version (socket path)')" />
                        <select id="php_version" wire:model="form.php_version" class="mt-1 block w-full max-w-xs rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30">
                            <option value="">{{ __('Select a PHP version') }}</option>
                            @foreach ($phpVersions as $version)
                                <option value="{{ $version['id'] }}">{{ $version['label'] }}</option>
                            @endforeach
                        </select>
                        @if ($phpVersions !== [])
                            <p class="mt-2 text-sm text-brand-moss">{{ __('Matches') }} <code class="rounded bg-brand-sand/60 px-1 py-0.5 text-xs text-brand-ink">/run/php/php{version}-fpm.sock</code> {{ __('on Ubuntu.') }}</p>
                        @else
                            <p class="mt-2 text-sm text-brand-moss">{{ __('No supported PHP versions are currently installed on this server. Install one from the server PHP workspace before creating a PHP site.') }}</p>
                        @endif
                        <x-input-error :messages="$errors->get('form.php_version')" class="mt-2" />
                    </div>
                @endif
                @if ($form->type === 'node')
                    <div>
                        <x-input-label for="app_port" :value="__('App listens on (localhost)')" />
                        <x-text-input id="app_port" type="number" wire:model="form.app_port" class="mt-1 block w-full max-w-[8rem]" />
                    </div>
                @endif
                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center">
                    <a
                        href="{{ route('servers.sites', $server) }}"
                        wire:navigate
                        class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-5 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                    >
                        {{ __('Cancel') }}
                    </a>
                    <x-primary-button type="submit">{{ __('Create site') }}</x-primary-button>
                </div>
            </form>
        </div>
    </div>
</x-server-workspace-shell>

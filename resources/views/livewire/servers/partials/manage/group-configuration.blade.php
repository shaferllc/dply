@php
    $meta = $server->meta ?? [];
    $disc = is_array($meta['manage_discovered'] ?? null) ? $meta['manage_discovered'] : [];
    $defaultPhp = (string) ($meta['default_php_version'] ?? '8.3');

    // Build the curated "Common files" group (label + path).
    $commonFiles = [
        ['label' => 'nginx.conf', 'path' => '/etc/nginx/nginx.conf'],
        ['label' => 'sshd_config', 'path' => '/etc/ssh/sshd_config'],
        ['label' => 'my.cnf', 'path' => '/etc/mysql/my.cnf'],
        ['label' => 'redis.conf', 'path' => '/etc/redis/redis.conf'],
        ['label' => 'php.ini ('.$defaultPhp.' fpm)', 'path' => '/etc/php/'.$defaultPhp.'/fpm/php.ini'],
        ['label' => 'unattended-upgrades', 'path' => '/etc/apt/apt.conf.d/50unattended-upgrades'],
        ['label' => '20auto-upgrades', 'path' => '/etc/apt/apt.conf.d/20auto-upgrades'],
        ['label' => 'supervisord.conf', 'path' => '/etc/supervisor/supervisord.conf'],
    ];
@endphp

<section class="space-y-6" aria-labelledby="manage-configuration-title">
    <div class="{{ $card }} p-6 sm:p-8">
        <h2 id="manage-configuration-title" class="text-lg font-semibold text-brand-ink">{{ __('Configuration files') }}</h2>
        <p class="mt-2 text-sm text-brand-moss leading-relaxed">
            {{ __('Read-only previews of allowlisted paths over SSH. Click a file to fetch the first portion of its contents into the panel above. Editing is not in scope.') }}
        </p>

        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Common files') }}</h3>
                    <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                        @foreach ($commonFiles as $file)
                            <button
                                type="button"
                                wire:click="previewConfigPath(@js($file['path']))"
                                @disabled(! $opsReady || $isDeployer)
                                class="flex items-center justify-between gap-2 rounded-lg border border-brand-ink/10 bg-white px-3 py-2 text-left text-sm hover:border-brand-sage/40 hover:bg-brand-sand/15 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <span class="flex items-center gap-2">
                                    <x-heroicon-o-document-magnifying-glass class="h-4 w-4 text-brand-moss" aria-hidden="true" />
                                    <span class="font-medium text-brand-ink">{{ $file['label'] }}</span>
                                </span>
                                <span class="font-mono text-[10px] text-brand-mist">{{ $file['path'] }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>

                @if (! empty($disc['nginx_sites_enabled']))
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('nginx sites-enabled') }}</h3>
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            @foreach ($disc['nginx_sites_enabled'] as $name)
                                <button
                                    type="button"
                                    wire:click="previewConfigPath(@js('/etc/nginx/sites-enabled/'.$name))"
                                    @disabled(! $opsReady || $isDeployer)
                                    class="rounded-md border border-brand-ink/10 bg-white px-2.5 py-1 font-mono text-xs text-brand-ink hover:border-brand-sage/40 hover:bg-brand-sand/15 disabled:cursor-not-allowed disabled:opacity-50"
                                >{{ $name }}</button>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if (! empty($disc['nginx_conf_d']))
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('nginx conf.d') }}</h3>
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            @foreach ($disc['nginx_conf_d'] as $name)
                                <button
                                    type="button"
                                    wire:click="previewConfigPath(@js('/etc/nginx/conf.d/'.$name))"
                                    @disabled(! $opsReady || $isDeployer)
                                    class="rounded-md border border-brand-ink/10 bg-white px-2.5 py-1 font-mono text-xs text-brand-ink hover:border-brand-sage/40 hover:bg-brand-sand/15 disabled:cursor-not-allowed disabled:opacity-50"
                                >{{ $name }}</button>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if (! empty($disc['supervisor_conf_d']))
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Supervisor conf.d') }}</h3>
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            @foreach ($disc['supervisor_conf_d'] as $name)
                                <button
                                    type="button"
                                    wire:click="previewConfigPath(@js('/etc/supervisor/conf.d/'.$name))"
                                    @disabled(! $opsReady || $isDeployer)
                                    class="rounded-md border border-brand-ink/10 bg-white px-2.5 py-1 font-mono text-xs text-brand-ink hover:border-brand-sage/40 hover:bg-brand-sand/15 disabled:cursor-not-allowed disabled:opacity-50"
                                >{{ $name }}</button>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <aside class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4 text-xs text-brand-moss leading-relaxed">
                <p class="font-semibold text-brand-ink">{{ __('How this works') }}</p>
                <p class="mt-1">{{ __('Output streams into the SSH panel at the top of the page. The first :n bytes of each file are fetched.', ['n' => number_format((int) config('server_manage.config_preview_max_bytes', 48000))]) }}</p>
                <p class="mt-2">{{ __('Allowed prefixes:') }}</p>
                <ul class="mt-1 space-y-0.5 font-mono">
                    @foreach (config('server_manage.allowed_config_path_prefixes', []) as $prefix)
                        <li>{{ $prefix }}</li>
                    @endforeach
                    @foreach (config('server_manage.allowed_config_paths_exact', []) as $exact)
                        <li>{{ $exact }} <span class="font-sans text-brand-mist">({{ __('exact') }})</span></li>
                    @endforeach
                </ul>
            </aside>
        </div>
    </div>
</section>

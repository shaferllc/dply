<div>
    <header class="border-b border-slate-200 bg-white">
        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8 flex justify-between items-center flex-wrap gap-2">
            <div>
                <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ $site->name }}</h2>
                <p class="text-sm text-slate-500">{{ $server->name }} · {{ $site->type->label() }}</p>
            </div>
            <a href="{{ route('servers.show', $server) }}" class="text-slate-500 hover:text-slate-700 text-sm">← Server</a>
        </div>
    </header>
    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if ($flash_success)
                <div class="p-4 rounded-md bg-green-50 text-green-800">{{ $flash_success }}</div>
            @endif
            @if ($flash_error)
                <div class="p-4 rounded-md bg-red-50 text-red-800">{{ $flash_error }}</div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-medium text-slate-900 mb-3">Status</h3>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                    <div><dt class="text-slate-500">Provisioning</dt><dd class="font-medium capitalize">{{ str_replace('_', ' ', $site->status) }}</dd></div>
                    <div><dt class="text-slate-500">SSL</dt><dd class="font-medium capitalize">{{ $site->ssl_status }}</dd></div>
                    <div><dt class="text-slate-500">Document root</dt><dd class="font-mono text-xs break-all">{{ $site->document_root }}</dd></div>
                    <div><dt class="text-slate-500">Deploy path</dt><dd class="font-mono text-xs break-all">{{ $site->effectiveRepositoryPath() }}</dd></div>
                </dl>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-medium text-slate-900 mb-3">Domains</h3>
                <ul class="divide-y divide-slate-100 mb-4">
                    @foreach ($site->domains as $d)
                        <li class="py-2 flex justify-between items-center gap-2">
                            <span class="font-mono text-sm">{{ $d->hostname }} @if ($d->is_primary)<span class="text-slate-400">(primary)</span>@endif</span>
                            @if (! $d->is_primary)
                                <button type="button" wire:click="removeDomain({{ $d->id }})" wire:confirm="Remove this domain?" class="text-red-600 text-sm hover:underline">Remove</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <form wire:submit="addDomain" class="flex flex-wrap gap-2 items-end">
                    <div class="flex-1 min-w-[200px]">
                        <x-input-label for="new_domain_hostname" value="Add domain" />
                        <x-text-input id="new_domain_hostname" wire:model="new_domain_hostname" class="mt-1 block w-full font-mono text-sm" placeholder="www.example.com" />
                        <x-input-error :messages="$errors->get('new_domain_hostname')" class="mt-1" />
                    </div>
                    <x-primary-button type="submit" class="!py-2">Add</x-primary-button>
                </form>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="font-medium text-slate-900">Nginx (HTTP)</h3>
                <p class="text-sm text-slate-600">Writes a vhost under <code class="bg-slate-100 px-1 rounded text-xs">sites-available</code>, symlinks to <code class="bg-slate-100 px-1 rounded text-xs">sites-enabled</code>, runs <code class="bg-slate-100 px-1 rounded text-xs">nginx -t</code> and reloads. Server must have Nginx installed; PHP sites need matching PHP-FPM.</p>
                @if ($server->isReady() && $server->ssh_private_key)
                    <button type="button" wire:click="installNginx" wire:loading.attr="disabled" class="inline-flex items-center px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-md hover:bg-slate-800 disabled:opacity-50">
                        <span wire:loading.remove wire:target="installNginx">Install / update Nginx site</span>
                        <span wire:loading wire:target="installNginx">Working…</span>
                    </button>
                @else
                    <p class="text-sm text-amber-700">SSH key required on the server record.</p>
                @endif
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="font-medium text-slate-900">Let’s Encrypt (Certbot)</h3>
                <p class="text-sm text-slate-600">Run after HTTP vhost works and DNS points here. Uses <code class="bg-slate-100 px-1 rounded text-xs">certbot --nginx</code>. Set <code class="bg-slate-100 px-1 rounded text-xs">DPLY_CERTBOT_EMAIL</code> in <code class="bg-slate-100 px-1 rounded text-xs">.env</code> or ensure your user/org has an email.</p>
                @if ($server->isReady() && $server->ssh_private_key)
                    <button type="button" wire:click="issueSsl" wire:loading.attr="disabled" class="inline-flex items-center px-4 py-2 bg-emerald-800 text-white text-sm font-medium rounded-md hover:bg-emerald-900 disabled:opacity-50">
                        <span wire:loading.remove wire:target="issueSsl">Issue / renew SSL</span>
                        <span wire:loading wire:target="issueSsl">Certbot…</span>
                    </button>
                @endif
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="font-medium text-slate-900">Git & deploy</h3>
                <form wire:submit="saveGit" class="space-y-3">
                    <div>
                        <x-input-label for="git_repository_url" value="Repository URL" />
                        <x-text-input id="git_repository_url" wire:model="git_repository_url" class="mt-1 block w-full font-mono text-sm" placeholder="git@github.com:org/repo.git" />
                    </div>
                    <div>
                        <x-input-label for="git_branch" value="Branch" />
                        <x-text-input id="git_branch" wire:model="git_branch" class="mt-1 block w-full w-48" />
                    </div>
                    <div>
                        <x-input-label for="post_deploy_command" value="Post-deploy command (run after git pull)" />
                        <textarea id="post_deploy_command" wire:model="post_deploy_command" rows="3" class="w-full rounded-md border-slate-300 shadow-sm font-mono text-sm" placeholder="composer install --no-dev && php artisan migrate --force"></textarea>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-primary-button type="submit">Save</x-primary-button>
                        <button type="button" wire:click="generateDeployKey" class="px-4 py-2 border border-slate-300 rounded-md text-sm text-slate-700 bg-white hover:bg-slate-50">Generate deploy key</button>
                    </div>
                </form>
                @if ($site->git_deploy_key_public)
                    <div>
                        <p class="text-sm text-slate-600 mb-1">Public key (add to GitHub / GitLab deploy keys):</p>
                        <pre class="bg-slate-900 text-green-400 p-3 rounded text-xs overflow-x-auto whitespace-pre-wrap">{{ $site->git_deploy_key_public }}</pre>
                    </div>
                @endif
                <div class="flex flex-wrap gap-2 pt-2">
                    <button type="button" wire:click="deployNow" wire:loading.attr="disabled" class="inline-flex items-center px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-md hover:bg-slate-800 disabled:opacity-50">
                        <span wire:loading.remove wire:target="deployNow">Deploy now (sync)</span>
                        <span wire:loading wire:target="deployNow">Deploying…</span>
                    </button>
                    <button type="button" wire:click="queueDeploy" class="px-4 py-2 border border-slate-300 rounded-md text-sm text-slate-700 bg-white hover:bg-slate-50">Queue deploy (queue worker)</button>
                </div>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                <h3 class="font-medium text-slate-900">Deploy webhook</h3>
                <p class="text-sm text-slate-600">POST JSON (or empty body) with header <code class="bg-slate-100 px-1 rounded text-xs">X-Dply-Signature: sha256=&lt;hmac&gt;</code> where <code class="bg-slate-100 px-1 rounded text-xs">hmac</code> is <code class="bg-slate-100 px-1 rounded text-xs">hash_hmac('sha256', raw_body, secret)</code>.</p>
                <p class="text-sm font-mono break-all bg-slate-50 p-2 rounded">{{ $deployHookUrl }}</p>
                @if ($revealed_webhook_secret)
                    <p class="text-sm text-amber-800 font-medium">Copy your new secret now:</p>
                    <pre class="bg-slate-900 text-amber-200 p-3 rounded text-xs overflow-x-auto">{{ $revealed_webhook_secret }}</pre>
                @else
                    <p class="text-sm text-slate-500">Secret is stored encrypted. Rotate to see a new one.</p>
                @endif
                <button type="button" wire:click="regenerateWebhookSecret" class="text-sm text-slate-700 underline">Rotate webhook secret</button>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3" wire:poll.10s>
                <h3 class="font-medium text-slate-900">Deployment log</h3>
                @if ($site->deployments->isEmpty())
                    <p class="text-sm text-slate-500">No deployments yet.</p>
                @else
                    <ul class="space-y-4">
                        @foreach ($site->deployments as $dep)
                            <li class="border border-slate-200 rounded-md p-3 text-sm">
                                <div class="flex flex-wrap justify-between gap-2 mb-2">
                                    <span class="font-medium capitalize">{{ $dep->trigger }} · {{ $dep->status }}</span>
                                    <span class="text-slate-500 text-xs">{{ $dep->created_at->diffForHumans() }}</span>
                                </div>
                                @if ($dep->git_sha)
                                    <p class="text-xs font-mono text-slate-600 mb-1">{{ $dep->git_sha }}</p>
                                @endif
                                @if ($dep->log_output)
                                    <pre class="bg-slate-900 text-slate-200 p-2 rounded text-xs overflow-x-auto max-h-48 overflow-y-auto whitespace-pre-wrap">{{ \Illuminate\Support\Str::limit($dep->log_output, 8000) }}</pre>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                <h3 class="font-medium text-slate-900">Environment (.env)</h3>
                <p class="text-sm text-slate-600">Stored encrypted in Dply. Push writes to <code class="bg-slate-100 px-1 rounded text-xs">{{ $site->effectiveRepositoryPath() }}/.env</code>.</p>
                <textarea wire:model="env_file_content" rows="8" class="w-full rounded-md border-slate-300 shadow-sm font-mono text-xs" placeholder="APP_NAME=…"></textarea>
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="saveEnvDraft" class="px-4 py-2 border border-slate-300 rounded-md text-sm text-slate-700 bg-white hover:bg-slate-50">Save draft in Dply</button>
                    <button type="button" wire:click="pushEnvToServer" wire:loading.attr="disabled" class="inline-flex items-center px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-md hover:bg-slate-800 disabled:opacity-50">
                        <span wire:loading.remove wire:target="pushEnvToServer">Push .env to server</span>
                        <span wire:loading wire:target="pushEnvToServer">Pushing…</span>
                    </button>
                </div>
            </div>

            <div class="flex justify-between items-center">
                <button type="button" wire:click="deleteSite" wire:confirm="Delete this site from Dply? Nginx files on the server are not removed automatically." class="text-red-600 hover:underline text-sm">Delete site</button>
            </div>
        </div>
    </div>
</div>

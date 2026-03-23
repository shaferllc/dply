<div>
    <header class="border-b border-slate-200 bg-white">
        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ $server->name }}</h2>
                <a href="{{ route('servers.index') }}" class="text-slate-500 hover:text-slate-700 text-sm">← Servers</a>
            </div>
        </div>
    </header>
    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('success') || $flash_success)
                <div class="p-4 rounded-md bg-green-50 text-green-800">{{ $flash_success ?? session('success') }}</div>
            @endif
            @if (session('error') || $flash_error)
                <div class="p-4 rounded-md bg-amber-50 text-amber-800">{{ $flash_error ?? session('error') }}</div>
            @endif
            @if ($command_output)
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-4">
                    <p class="text-sm font-medium text-slate-700 mb-2">Command output:</p>
                    <pre class="bg-slate-900 text-green-400 p-4 rounded text-sm overflow-x-auto">{{ $command_output }}</pre>
                </div>
            @endif
            @if ($command_error)
                <div class="p-4 rounded-md bg-red-50 text-red-800">{{ $command_error }}</div>
            @endif
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="font-medium text-slate-900 mb-4">Server details</h3>
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div><dt class="text-sm text-slate-500">Status</dt><dd class="font-medium">{{ $server->status }}</dd></div>
                    <div><dt class="text-sm text-slate-500">Provider</dt><dd class="font-medium">{{ $server->provider->label() }}</dd></div>
                    @if ($server->setup_script_key)
                        <div><dt class="text-sm text-slate-500">Setup script</dt><dd class="font-medium">{{ config("setup_scripts.scripts.{$server->setup_script_key}.name", $server->setup_script_key) }}</dd></div>
                        <div><dt class="text-sm text-slate-500">Setup status</dt><dd class="font-medium">
                            @if ($server->setup_status === 'done')
                                <span class="text-green-600">Done</span>
                            @elseif ($server->setup_status === 'failed')
                                <span class="text-red-600">Failed</span>
                            @elseif ($server->setup_status === 'running')
                                <span class="text-amber-600">Running</span>
                            @else
                                <span class="text-slate-500">{{ $server->setup_status ?? 'Pending' }}</span>
                            @endif
                        </dd></div>
                    @endif
                    <div><dt class="text-sm text-slate-500">IP address</dt><dd class="font-medium font-mono">{{ $server->ip_address ?? '—' }}</dd></div>
                    <div><dt class="text-sm text-slate-500">SSH</dt><dd class="font-medium font-mono">{{ $server->getSshConnectionString() }}</dd></div>
                    @if ($server->status === 'ready')
                        <div><dt class="text-sm text-slate-500">Health</dt><dd class="font-medium">
                            @if ($server->health_status === 'reachable')
                                <span class="text-green-600">Reachable</span>
                            @elseif ($server->health_status === 'unreachable')
                                <span class="text-red-600">Unreachable</span>
                            @else
                                <span class="text-slate-500">—</span>
                            @endif
                            @if ($server->last_health_check_at)
                                <span class="text-slate-400 text-sm">({{ $server->last_health_check_at->diffForHumans() }})</span>
                            @endif
                        </dd></div>
                    @endif
                </dl>
                @if ($server->status === 'ready' && $server->ip_address)
                    <div class="mt-4 space-y-2">
                        <button type="button" wire:click="checkHealth" class="text-sm text-slate-600 hover:text-slate-800 underline">Check health now</button>
                        <div class="pt-2 border-t border-slate-100">
                            <p class="text-sm text-slate-500 mb-1">Optional: use an HTTP URL for health (e.g. <code class="bg-slate-100 px-1 rounded">https://yoursite.com/up</code>). If set, 2xx = reachable; otherwise SSH port is checked.</p>
                            <form wire:submit="saveHealthCheckUrl" class="flex gap-2 items-center">
                                <input type="url" wire:model="health_check_url" placeholder="https://…" class="flex-1 max-w-md rounded-md border-slate-300 shadow-sm text-sm">
                                <x-primary-button type="submit" class="!py-1.5 text-sm">Save</x-primary-button>
                            </form>
                        </div>
                    </div>
                @endif
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-medium text-slate-900">Sites</h3>
                    @if ($server->isReady())
                        <a href="{{ route('sites.create', $server) }}" class="text-sm font-medium text-slate-800 hover:underline">+ New site</a>
                    @endif
                </div>
                @if ($server->sites->isEmpty())
                    <p class="text-sm text-slate-600">No sites yet. Create a site to manage Nginx, SSL, Git deploys, and .env.</p>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($server->sites as $s)
                            <li class="py-2 flex justify-between items-center gap-2">
                                <a href="{{ route('sites.show', [$server, $s]) }}" class="font-medium text-slate-900 hover:underline">{{ $s->name }}</a>
                                <span class="text-xs text-slate-500 capitalize">{{ str_replace('_', ' ', $s->status) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @if ($server->isReady() && $server->ssh_private_key)
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 space-y-4">
                    <h3 class="font-medium text-slate-900">Databases (MySQL / Postgres)</h3>
                    <p class="text-sm text-slate-600">Creates a DB and user on the server via SSH (requires <code class="bg-slate-100 px-1 rounded text-xs">mysql</code> or <code class="bg-slate-100 px-1 rounded text-xs">psql</code> as appropriate). MySQL uses passwordless <code class="bg-slate-100 px-1 rounded text-xs">root</code> over the socket — typical on Ubuntu cloud images after <code class="bg-slate-100 px-1 rounded text-xs">mysql-server</code>.</p>
                    @if ($server->serverDatabases->isNotEmpty())
                        <ul class="text-sm space-y-2">
                            @foreach ($server->serverDatabases as $db)
                                <li class="flex justify-between items-center border border-slate-100 rounded-md px-3 py-2">
                                    <span><span class="font-mono">{{ $db->name }}</span> <span class="text-slate-500">({{ $db->engine }})</span></span>
                                    <button type="button" wire:click="deleteDatabase({{ $db->id }})" wire:confirm="Remove this entry from Dply?" class="text-red-600 text-xs hover:underline">Remove</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <form wire:submit="createDatabase" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <x-input-label for="new_db_name" value="Database name" />
                            <x-text-input id="new_db_name" wire:model="new_db_name" class="mt-1 block w-full font-mono text-sm" />
                            <x-input-error :messages="$errors->get('new_db_name')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="new_db_engine" value="Engine" />
                            <select id="new_db_engine" wire:model="new_db_engine" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm text-sm">
                                <option value="mysql">MySQL / MariaDB</option>
                                <option value="postgres">PostgreSQL</option>
                            </select>
                        </div>
                        <div>
                            <x-input-label for="new_db_username" value="Username" />
                            <x-text-input id="new_db_username" wire:model="new_db_username" class="mt-1 block w-full font-mono text-sm" />
                            <x-input-error :messages="$errors->get('new_db_username')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="new_db_password" value="Password" />
                            <x-text-input id="new_db_password" type="password" wire:model="new_db_password" class="mt-1 block w-full text-sm" />
                            <x-input-error :messages="$errors->get('new_db_password')" class="mt-1" />
                        </div>
                        <div class="sm:col-span-2">
                            <x-input-label for="new_db_host" value="Host (metadata)" />
                            <x-text-input id="new_db_host" wire:model="new_db_host" class="mt-1 block w-full font-mono text-sm" />
                        </div>
                        <div class="sm:col-span-2">
                            <x-primary-button type="submit">Create on server</x-primary-button>
                        </div>
                    </form>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 space-y-4">
                    <h3 class="font-medium text-slate-900">Cron (Dply-managed block)</h3>
                    <p class="text-sm text-slate-600">Entries are merged into the SSH user’s crontab inside markers. Commands run as <span class="font-mono">{{ $server->ssh_user }}</span> (the <code class="bg-slate-100 px-1 rounded text-xs">user</code> field is stored for your notes only).</p>
                    @if ($server->cronJobs->isNotEmpty())
                        <ul class="text-sm space-y-2">
                            @foreach ($server->cronJobs as $cj)
                                <li class="flex flex-col sm:flex-row sm:justify-between sm:items-start gap-2 border border-slate-100 rounded-md px-3 py-2">
                                    <div>
                                        <p class="font-mono text-xs text-slate-800">{{ $cj->cron_expression }}</p>
                                        <p class="font-mono text-xs text-slate-600 break-all">{{ $cj->command }}</p>
                                        @if ($cj->is_synced)
                                            <span class="text-xs text-green-600">Synced</span>
                                        @elseif ($cj->last_sync_error)
                                            <span class="text-xs text-red-600">Sync issue</span>
                                        @endif
                                    </div>
                                    <button type="button" wire:click="deleteCronJob({{ $cj->id }})" class="text-red-600 text-xs hover:underline shrink-0">Remove</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <form wire:submit="addCronJob" class="space-y-3">
                        <div>
                            <x-input-label for="new_cron_expression" value="Cron expression" />
                            <x-text-input id="new_cron_expression" wire:model="new_cron_expression" class="mt-1 block w-full font-mono text-sm" />
                            <x-input-error :messages="$errors->get('new_cron_expression')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="new_cron_command" value="Command" />
                            <x-text-input id="new_cron_command" wire:model="new_cron_command" class="mt-1 block w-full font-mono text-sm" />
                            <x-input-error :messages="$errors->get('new_cron_command')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="new_cron_user" value="User (label only)" />
                            <x-text-input id="new_cron_user" wire:model="new_cron_user" class="mt-1 block w-full font-mono text-sm" />
                        </div>
                        <x-primary-button type="submit">Add entry</x-primary-button>
                    </form>
                    <button type="button" wire:click="syncCronJobs" class="inline-flex items-center px-4 py-2 border border-slate-300 rounded-md text-sm text-slate-800 bg-white hover:bg-slate-50">Sync crontab on server</button>
                    <p class="text-xs text-slate-500 mt-2">Crontab sync also writes the <strong>Laravel scheduler</strong> block for any site with “Laravel scheduler” enabled.</p>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 space-y-4">
                    <h3 class="font-medium text-slate-900">Supervisor (daemons)</h3>
                    <p class="text-sm text-slate-600">Writes <code class="text-xs bg-slate-100 px-1">/etc/supervisor/conf.d/dply-sv-*.conf</code> and runs <code class="text-xs bg-slate-100 px-1">supervisorctl reread/update</code>. Use for Horizon, queue workers, Octane, etc.</p>
                    @if ($server->supervisorPrograms->isNotEmpty())
                        <ul class="text-sm space-y-2">
                            @foreach ($server->supervisorPrograms as $sp)
                                <li class="flex justify-between gap-2 border border-slate-100 rounded px-3 py-2">
                                    <div>
                                        <span class="font-mono font-medium">{{ $sp->slug }}</span>
                                        <span class="text-slate-500 text-xs">({{ $sp->program_type }})</span>
                                        <p class="font-mono text-xs text-slate-600 break-all">{{ $sp->command }}</p>
                                        <p class="text-xs text-slate-500">{{ $sp->directory }} · user {{ $sp->user }}</p>
                                    </div>
                                    <button type="button" wire:click="deleteSupervisorProgram({{ $sp->id }})" class="text-red-600 text-xs hover:underline shrink-0">Remove</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <form wire:submit="addSupervisorProgram" class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                        <x-text-input wire:model="new_sv_slug" placeholder="slug (e.g. horizon)" class="font-mono" />
                        <select wire:model="new_sv_type" class="rounded-md border-slate-300">
                            <option value="horizon">horizon</option>
                            <option value="queue">queue</option>
                            <option value="octane">octane</option>
                            <option value="custom">custom</option>
                        </select>
                        <x-text-input wire:model="new_sv_command" placeholder="php artisan horizon" class="sm:col-span-2 font-mono text-xs" />
                        <x-text-input wire:model="new_sv_directory" placeholder="/var/www/app/current" class="sm:col-span-2 font-mono text-xs" />
                        <x-text-input wire:model="new_sv_user" placeholder="www-data" />
                        <x-text-input type="number" wire:model="new_sv_numprocs" class="w-20" min="1" max="32" />
                        <div class="sm:col-span-2 flex gap-2">
                            <x-primary-button type="submit" class="!py-2 text-sm">Add program</x-primary-button>
                            <button type="button" wire:click="syncSupervisor" class="px-4 py-2 border border-slate-300 rounded-md text-sm">Sync Supervisor</button>
                        </div>
                    </form>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 space-y-4">
                    <h3 class="font-medium text-slate-900">Firewall (UFW allow)</h3>
                    <p class="text-sm text-amber-800">Runs <code class="text-xs bg-slate-100 px-1">ufw allow port/proto</code> for each rule. Confirm SSH access before relying on UFW.</p>
                    @if ($server->firewallRules->isNotEmpty())
                        <ul class="text-sm space-y-1">
                            @foreach ($server->firewallRules as $fr)
                                <li class="flex justify-between">
                                    <span>Allow {{ $fr->port }}/{{ $fr->protocol }}</span>
                                    <button type="button" wire:click="deleteFirewallRule({{ $fr->id }})" class="text-red-600 text-xs hover:underline">Remove</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <form wire:submit="addFirewallRule" class="flex flex-wrap gap-2 items-end">
                        <x-text-input type="number" wire:model="new_fw_port" class="w-24" />
                        <select wire:model="new_fw_protocol" class="rounded-md border-slate-300 text-sm">
                            <option value="tcp">tcp</option>
                            <option value="udp">udp</option>
                        </select>
                        <x-primary-button type="submit" class="!py-2 text-sm">Add rule</x-primary-button>
                        <button type="button" wire:click="applyFirewall" class="px-4 py-2 border border-slate-300 rounded-md text-sm">Apply UFW rules</button>
                    </form>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 space-y-4">
                    <h3 class="font-medium text-slate-900">Extra SSH public keys</h3>
                    <p class="text-sm text-slate-600">Merges into the SSH user’s <code class="text-xs bg-slate-100 px-1">~/.ssh/authorized_keys</code> (same user as the server’s Dply SSH key).</p>
                    @if ($server->authorizedKeys->isNotEmpty())
                        <ul class="text-sm space-y-1">
                            @foreach ($server->authorizedKeys as $ak)
                                <li class="flex justify-between gap-2">
                                    <span>{{ $ak->name }}</span>
                                    <button type="button" wire:click="deleteAuthorizedKey({{ $ak->id }})" class="text-red-600 text-xs hover:underline">Remove</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <form wire:submit="addAuthorizedKey" class="space-y-2">
                        <x-text-input wire:model="new_auth_name" placeholder="Label (e.g. Alice laptop)" />
                        <textarea wire:model="new_auth_key" rows="3" class="w-full rounded-md border-slate-300 font-mono text-xs" placeholder="ssh-ed25519 AAAA…"></textarea>
                        <div class="flex gap-2">
                            <x-primary-button type="submit" class="!py-2 text-sm">Save key</x-primary-button>
                            <button type="button" wire:click="syncAuthorizedKeys" class="px-4 py-2 border border-slate-300 rounded-md text-sm">Sync authorized_keys</button>
                        </div>
                    </form>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 space-y-4">
                    <h3 class="font-medium text-slate-900">Recipes (saved bash)</h3>
                    @if ($server->recipes->isNotEmpty())
                        <ul class="text-sm space-y-2">
                            @foreach ($server->recipes as $rec)
                                <li class="flex justify-between items-center border border-slate-100 rounded px-3 py-2">
                                    <span class="font-medium">{{ $rec->name }}</span>
                                    <span class="flex gap-2">
                                        <button type="button" wire:click="runRecipe({{ $rec->id }})" class="text-slate-800 text-xs hover:underline">Run</button>
                                        <button type="button" wire:click="deleteRecipe({{ $rec->id }})" wire:confirm="Delete recipe?" class="text-red-600 text-xs hover:underline">Delete</button>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <form wire:submit="addRecipe" class="space-y-2">
                        <x-text-input wire:model="new_recipe_name" placeholder="Recipe name" />
                        <textarea wire:model="new_recipe_script" rows="6" class="w-full rounded-md border-slate-300 font-mono text-xs"></textarea>
                        <x-primary-button type="submit" class="!py-2 text-sm">Save recipe</x-primary-button>
                    </form>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <h3 class="font-medium text-slate-900 mb-4">Deploy</h3>
                    @if ($server->deploy_command)
                        <div class="mb-4">
                            <button type="button" wire:click="deploy" class="inline-flex items-center px-4 py-2 bg-slate-900 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-slate-800">Deploy</button>
                        </div>
                        <p class="text-sm text-slate-500">Runs the configured deploy command. Edit it below if needed.</p>
                    @else
                        <p class="text-slate-600 mb-2">No deploy command set. Add one below to run deployments with one click.</p>
                    @endif
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <h3 class="font-medium text-slate-900 mb-4">Edit deploy command</h3>
                    @php $deployTemplates = config('deploy_templates.templates', []); @endphp
                    @if (count($deployTemplates) > 0)
                        <p class="text-sm text-slate-600 mb-2">Use a template:</p>
                        <div class="flex flex-wrap gap-2 mb-4">
                            @foreach ($deployTemplates as $templateKey => $template)
                                <button type="button" wire:click="applyDeployTemplate('{{ $templateKey }}')" class="inline-flex items-center px-3 py-1.5 border border-slate-300 rounded-md text-sm text-slate-700 bg-white hover:bg-slate-50">
                                    {{ $template['name'] }}
                                </button>
                            @endforeach
                        </div>
                    @endif
                    <form wire:submit="updateDeployCommand">
                        <textarea wire:model="deploy_command" rows="3" placeholder="e.g. cd /var/www && git pull && composer install --no-dev && php artisan migrate --force" class="w-full rounded-md border-slate-300 shadow-sm"></textarea>
                        <p class="mt-1 text-sm text-slate-500">Example: <code class="bg-slate-100 px-1 rounded">cd /var/www && git pull && composer install --no-dev && php artisan migrate --force</code></p>
                        <x-primary-button type="submit" class="mt-3">Save deploy command</x-primary-button>
                    </form>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <h3 class="font-medium text-slate-900 mb-4">Run command</h3>
                    <form wire:submit="runCommand" class="flex gap-2">
                        <input type="text" wire:model="command" placeholder="e.g. uptime" class="flex-1 rounded-md border-slate-300 shadow-sm" required>
                        <x-primary-button type="submit">Run</x-primary-button>
                    </form>
                </div>
            @endif
            <div class="flex justify-between items-center">
                <button type="button" wire:click="destroy" wire:confirm="Remove this server? Cloud instances (DigitalOcean/Hetzner) will be destroyed." class="text-red-600 hover:underline text-sm">Remove server from Dply</button>
            </div>
        </div>
    </div>
</div>

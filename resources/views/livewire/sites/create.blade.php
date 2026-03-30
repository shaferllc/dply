<div>
    <header class="border-b border-slate-200 bg-white">
        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8 flex justify-between items-center">
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">New site on {{ $server->name }}</h2>
            <a href="{{ route('servers.show', $server) }}" class="text-slate-500 hover:text-slate-700 text-sm">← Server</a>
        </div>
    </header>
    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <form wire:submit="store" class="bg-white shadow-sm sm:rounded-lg p-6 space-y-6">
                <div>
                    <x-input-label for="name" value="Site name" />
                    <x-text-input id="name" wire:model="form.name" class="mt-1 block w-full" required />
                    <x-input-error :messages="$errors->get('form.name')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="primary_hostname" value="Primary domain (DNS must point to this server)" />
                    <x-text-input id="primary_hostname" wire:model="form.primary_hostname" placeholder="app.example.com" class="mt-1 block w-full font-mono text-sm" required />
                    <x-input-error :messages="$errors->get('form.primary_hostname')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="type" value="Stack" />
                    <select id="type" wire:model.live="form.type" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm">
                        <option value="php">PHP (PHP-FPM + Nginx)</option>
                        <option value="static">Static files</option>
                        <option value="node">Node (Nginx → reverse proxy)</option>
                    </select>
                </div>
                <div>
                    <x-input-label for="document_root" value="Document root (on server)" />
                    <x-text-input id="document_root" wire:model="form.document_root" class="mt-1 block w-full font-mono text-sm" required />
                    <p class="mt-1 text-sm text-slate-500">For Laravel use the <code class="bg-slate-100 px-1 rounded">public</code> directory.</p>
                    <x-input-error :messages="$errors->get('form.document_root')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="repository_path" value="Git / deploy path (optional)" />
                    <x-text-input id="repository_path" wire:model="form.repository_path" class="mt-1 block w-full font-mono text-sm" />
                    <p class="mt-1 text-sm text-slate-500">Where <code class="bg-slate-100 px-1 rounded">git pull</code> runs; defaults to document root if empty.</p>
                </div>
                @if ($form->type === 'php')
                    <div>
                        <x-input-label for="php_version" value="PHP-FPM version (socket path)" />
                        <x-text-input id="php_version" wire:model="form.php_version" class="mt-1 block w-full w-32" />
                        <p class="mt-1 text-sm text-slate-500">Matches <code class="bg-slate-100 px-1 rounded">/run/php/php{version}-fpm.sock</code> on Ubuntu.</p>
                    </div>
                @endif
                @if ($form->type === 'node')
                    <div>
                        <x-input-label for="app_port" value="App listens on (localhost)" />
                        <x-text-input id="app_port" type="number" wire:model="form.app_port" class="mt-1 block w-full w-32" />
                    </div>
                @endif
                <div class="flex gap-3">
                    <x-primary-button type="submit">Create site</x-primary-button>
                    <a href="{{ route('servers.show', $server) }}" class="inline-flex items-center px-4 py-2 border border-slate-300 rounded-md text-sm text-slate-700 bg-white hover:bg-slate-50">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

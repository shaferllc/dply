<div>
    <header class="border-b border-slate-200 bg-white">
        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Servers') }}</h2>
                <a href="{{ route('servers.create') }}" class="inline-flex items-center px-4 py-2 bg-slate-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-slate-700">
                    {{ __('Add Server') }}
                </a>
            </div>
        </div>
    </header>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="mb-4 p-4 rounded-md bg-green-50 text-green-800">{{ session('success') }}</div>
            @endif
            @if ($servers->isEmpty())
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-8 text-center text-slate-500">
                    <p class="mb-4">No servers yet. Connect DigitalOcean or add an existing server.</p>
                    <a href="{{ route('servers.create') }}" class="text-slate-700 hover:underline">Add your first server</a>
                </div>
            @else
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <ul class="divide-y divide-slate-200">
                        @foreach ($servers as $server)
                            <li class="flex items-center justify-between px-6 py-4 hover:bg-slate-50">
                                <div>
                                    <a href="{{ route('servers.show', $server) }}" class="font-medium text-slate-900">{{ $server->name }}</a>
                                    <p class="text-sm text-slate-500">
                                        {{ $server->ip_address ?? 'Provisioning…' }} · {{ $server->provider->label() }} · {{ $server->status }}
                                        @if ($server->status === 'ready')
                                            · <span class="inline-flex items-center gap-1">
                                                @if ($server->health_status === 'reachable')
                                                    <span class="text-green-600">Reachable</span>
                                                @elseif ($server->health_status === 'unreachable')
                                                    <span class="text-red-600">Unreachable</span>
                                                @else
                                                    <span class="text-slate-400">—</span>
                                                @endif
                                                @if ($server->last_health_check_at)
                                                    <span class="text-slate-400">({{ $server->last_health_check_at->diffForHumans() }})</span>
                                                @endif
                                            </span>
                                        @endif
                                    </p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('servers.show', $server) }}" class="text-slate-600 hover:underline text-sm">Manage</a>
                                    <button type="button" wire:click="destroy({{ $server->id }})" wire:confirm="Remove this server? Cloud instances will be destroyed." class="text-red-600 hover:underline text-sm">Remove</button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
</div>

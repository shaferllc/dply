<div>
    <header class="border-b border-slate-200 bg-white">
        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8 flex justify-between items-center">
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">Sites</h2>
            <a href="{{ route('servers.index') }}" class="text-slate-500 hover:text-slate-700 text-sm">Servers →</a>
        </div>
    </header>
    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            <p class="text-slate-600 mb-6">Sites belong to a server. Open a server to create one, or jump from the list below.</p>
            @if ($sites->isEmpty())
                <div class="bg-white shadow-sm sm:rounded-lg p-8 text-center text-slate-600">
                    <p class="mb-4">No sites yet. Provision a server, then add a site from the server page.</p>
                    <a href="{{ route('servers.index') }}" class="text-slate-800 font-medium hover:underline">Go to servers</a>
                </div>
            @else
                <ul class="divide-y divide-slate-200 bg-white shadow-sm sm:rounded-lg overflow-hidden">
                    @foreach ($sites as $site)
                        <li class="p-4 flex flex-wrap justify-between gap-4 items-center hover:bg-slate-50">
                            <div>
                                <a href="{{ route('sites.show', [$site->server, $site]) }}" class="font-medium text-slate-900 hover:underline">{{ $site->name }}</a>
                                <p class="text-sm text-slate-500">
                                    Server: {{ $site->server->name }}
                                    @php $d = $site->domains->firstWhere('is_primary') ?? $site->domains->first(); @endphp
                                    @if ($d)
                                        · {{ $d->hostname }}
                                    @endif
                                    · {{ $site->type->label() }}
                                </p>
                            </div>
                            <div class="text-sm text-slate-600">
                                <span class="capitalize">{{ str_replace('_', ' ', $site->status) }}</span>
                                @if ($site->ssl_status !== 'none')
                                    · SSL: {{ $site->ssl_status }}
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>

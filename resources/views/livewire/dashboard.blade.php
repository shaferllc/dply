<div>
    <header class="border-b border-slate-200 bg-white">
        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Dashboard') }}</h2>
        </div>
    </header>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="mb-6 flex gap-4">
                <a href="{{ route('credentials.index') }}" class="inline-flex items-center px-4 py-2 bg-white border border-slate-200 rounded-lg font-medium text-sm text-slate-700 shadow-sm hover:bg-slate-50">
                    {{ __('Provider credentials') }}
                </a>
                <a href="{{ route('servers.create') }}" class="inline-flex items-center px-4 py-2 bg-slate-900 border border-transparent rounded-lg font-medium text-sm text-white hover:bg-slate-800">
                    {{ __('Add server') }}
                </a>
            </div>
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-xl border border-slate-200">
                <div class="p-6">
                    <h3 class="font-medium text-slate-900 mb-4">Your servers</h3>
                    @if ($servers->isEmpty())
                        <p class="text-slate-500 mb-4">No servers yet. Create your first server to get started.</p>
                        <div class="flex flex-wrap gap-3">
                            <a href="{{ route('servers.create') }}" class="inline-flex items-center px-4 py-2 bg-slate-900 text-white rounded-lg font-medium text-sm hover:bg-slate-800">Create your first server</a>
                            <a href="{{ route('docs.connect-provider') }}" class="inline-flex items-center text-sm text-slate-600 hover:text-slate-900">New? Read the guide</a>
                            <span class="text-slate-400">·</span>
                            <a href="{{ route('docs.connect-provider') }}" class="inline-flex items-center text-sm text-slate-600 hover:text-slate-900">Connect DigitalOcean or Hetzner first</a>
                        </div>
                    @else
                        <ul class="divide-y divide-slate-200">
                            @foreach ($servers as $server)
                                <li class="py-2">
                                    <a href="{{ route('servers.show', $server) }}" class="text-slate-900 hover:text-slate-700 font-medium">{{ $server->name }}</a>
                                    <span class="text-slate-500 text-sm ml-2">{{ $server->ip_address ?? $server->status }}</span>
                                </li>
                            @endforeach
                        </ul>
                        <a href="{{ route('servers.index') }}" class="inline-block mt-2 text-sm font-medium text-slate-700 hover:text-slate-900">View all servers</a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

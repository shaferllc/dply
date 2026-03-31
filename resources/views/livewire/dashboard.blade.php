<div>
    <header class="border-b border-slate-200 bg-white">
        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Dashboard') }}</h2>
        </div>
    </header>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="mb-6 flex flex-wrap gap-4">
                <a href="{{ route('credentials.index') }}" class="inline-flex items-center px-4 py-2 bg-white border border-slate-200 rounded-lg font-medium text-sm text-slate-700 shadow-sm hover:bg-slate-50">
                    {{ __('Provider credentials') }}
                </a>
                <a href="{{ route('servers.create') }}" class="inline-flex items-center px-4 py-2 bg-slate-900 border border-transparent rounded-lg font-medium text-sm text-white hover:bg-slate-800">
                    {{ __('Add server') }}
                </a>
            </div>

            @if ($fleetInsights && ($fleetInsights['total_open'] > 0 || $fleetInsights['avg_health_score'] !== null))
                <div class="mb-6 rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Fleet insights') }}</h3>
                            <p class="mt-1 text-sm text-brand-moss">{{ __('Open findings across servers in this organization.') }}</p>
                        </div>
                        @if ($fleetInsights['avg_health_score'] !== null)
                            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/30 px-4 py-2 text-center">
                                <p class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Avg health score') }}</p>
                                <p class="text-2xl font-semibold text-brand-ink">{{ (int) $fleetInsights['avg_health_score'] }}</p>
                                <p class="text-xs text-brand-moss">{{ __('0–100 · higher is better') }}</p>
                            </div>
                        @endif
                    </div>
                    <dl class="mt-4 grid grid-cols-3 gap-3 text-center sm:max-w-md">
                        <div class="rounded-lg border border-red-200 bg-red-50/80 px-3 py-2">
                            <dt class="text-xs font-medium text-red-800">{{ __('Critical') }}</dt>
                            <dd class="text-lg font-semibold text-red-900">{{ $fleetInsights['open_by_severity']['critical'] }}</dd>
                        </div>
                        <div class="rounded-lg border border-amber-200 bg-amber-50/80 px-3 py-2">
                            <dt class="text-xs font-medium text-amber-900">{{ __('Warning') }}</dt>
                            <dd class="text-lg font-semibold text-amber-950">{{ $fleetInsights['open_by_severity']['warning'] }}</dd>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                            <dt class="text-xs font-medium text-slate-600">{{ __('Info') }}</dt>
                            <dd class="text-lg font-semibold text-slate-900">{{ $fleetInsights['open_by_severity']['info'] }}</dd>
                        </div>
                    </dl>
                    @if (! empty($fleetInsights['worst_servers']))
                        <div class="mt-4 border-t border-brand-ink/10 pt-4">
                            <p class="text-xs font-medium text-brand-moss mb-2">{{ __('Review first') }}</p>
                            <ul class="space-y-2">
                                @foreach ($fleetInsights['worst_servers'] as $row)
                                    <li class="flex flex-wrap items-center justify-between gap-2 text-sm">
                                        <a href="{{ route('servers.insights', $row['id']) }}" wire:navigate class="font-medium text-brand-ink hover:text-brand-sage">{{ $row['name'] }}</a>
                                        <span class="text-brand-moss">
                                            {{ trans_choice(':count open|:count open', $row['open'], ['count' => $row['open']]) }}
                                            @if ($row['worst'])
                                                <span class="text-brand-mist"> · </span>
                                                <span class="font-medium uppercase text-brand-ink">{{ $row['worst'] }}</span>
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    <div class="mt-4">
                        <a href="{{ route('servers.index') }}" wire:navigate class="text-sm font-semibold text-brand-sage hover:text-brand-forest">{{ __('Open servers list') }} →</a>
                    </div>
                </div>
            @endif

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

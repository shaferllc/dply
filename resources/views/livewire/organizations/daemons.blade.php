<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell :organization="$organization" section="daemons">
            <div>
                <header class="mb-8">
                    <h1 class="text-2xl font-semibold text-slate-800">{{ __('Supervisor (Daemons)') }}</h1>
                    <p class="mt-1 text-sm text-slate-600">
                        {{ __('Cross-server view of programs and last health snapshot. Open a server for full controls (sync, logs, restarts, drift).') }}
                    </p>
                </header>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="px-6 py-4 border-b border-slate-200">
                        <h2 class="font-medium text-slate-900">{{ __('Servers') }}</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-6 py-3 text-left font-medium text-slate-600">{{ __('Server') }}</th>
                                    <th class="px-6 py-3 text-left font-medium text-slate-600">{{ __('Programs') }}</th>
                                    <th class="px-6 py-3 text-left font-medium text-slate-600">{{ __('Package') }}</th>
                                    <th class="px-6 py-3 text-left font-medium text-slate-600">{{ __('Last health') }}</th>
                                    <th class="px-6 py-3 text-right font-medium text-slate-600">{{ __('') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @forelse ($servers as $srv)
                                    @php
                                        $health = $srv->meta['supervisor_health'] ?? null;
                                    @endphp
                                    <tr>
                                        <td class="px-6 py-3 font-medium text-slate-900">{{ $srv->name }}</td>
                                        <td class="px-6 py-3 tabular-nums text-slate-700">{{ $srv->supervisor_programs_count }}</td>
                                        <td class="px-6 py-3 text-slate-600">
                                            @if ($srv->supervisor_package_status === \App\Models\Server::SUPERVISOR_PACKAGE_INSTALLED)
                                                {{ __('Installed') }}
                                            @elseif ($srv->supervisor_package_status === \App\Models\Server::SUPERVISOR_PACKAGE_MISSING)
                                                {{ __('Missing') }}
                                            @else
                                                {{ __('Unknown') }}
                                            @endif
                                        </td>
                                        <td class="px-6 py-3 text-slate-600 max-w-md">
                                            @if (is_array($health))
                                                <span @class(['text-emerald-700' => ($health['ok'] ?? false), 'text-amber-800' => ! ($health['ok'] ?? true)])>
                                                    {{ $health['summary'] ?? '—' }}
                                                </span>
                                                @if (! empty($health['checked_at']))
                                                    <span class="block text-xs text-slate-400 mt-0.5">{{ $health['checked_at'] }}</span>
                                                @endif
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="px-6 py-3 text-right">
                                            <a
                                                href="{{ route('servers.daemons', $srv) }}"
                                                wire:navigate
                                                class="text-indigo-600 hover:text-indigo-800 text-sm font-medium"
                                            >{{ __('Daemons') }}</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-6 py-8 text-center text-slate-500">{{ __('No servers in this organization.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </x-organization-shell>
    </div>
</div>

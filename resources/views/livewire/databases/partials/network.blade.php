@if (! $canManageNetwork)
    @include('livewire.databases.partials._unavailable', [
        'title' => __('Network access is not managed here'),
        'reason' => __('This backend has no allowlist dply can write to. Its cluster is reachable per the provider\'s own settings.'),
    ])
@else
    <div class="space-y-6">
        <form wire:submit="allowIp" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <label for="trusted-ip" class="text-sm font-semibold text-slate-900">{{ __('Grant temporary access') }}</label>
            <p class="mt-1 text-xs text-slate-600">
                {{ __('Adds a public IP to the cluster\'s allowlist for :hours hours, then removes it automatically. Rules dply did not create are never touched.', ['hours' => $trustedSourceTtlHours]) }}
            </p>
            <div class="mt-3 flex flex-wrap gap-3">
                <input id="trusted-ip" type="text" wire:model="trustedIp"
                    placeholder="203.0.113.10"
                    class="min-w-0 flex-1 rounded-xl border-slate-300 font-mono text-sm shadow-sm focus:border-slate-900 focus:ring-slate-900" />
                <button type="submit" class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                    {{ __('Allow IP') }}
                </button>
            </div>
        </form>

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-sm font-semibold text-slate-900">{{ __('Active grants') }}</h2>
                <p class="mt-1 text-xs text-slate-600">{{ __('Temporary access dply is tracking. App servers are allowlisted permanently and are not listed here.') }}</p>
            </div>

            @if ($trustedSources->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-slate-600">{{ __('No temporary grants are active.') }}</p>
            @else
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">
                        <tr>
                            <th class="px-5 py-3">{{ __('IP address') }}</th>
                            <th class="px-5 py-3">{{ __('Granted to') }}</th>
                            <th class="px-5 py-3">{{ __('Expires') }}</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-800">
                        @foreach ($trustedSources as $source)
                            <tr>
                                <td class="px-5 py-3 font-mono text-xs text-slate-900">{{ $source->ip_address }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $source->createdBy?->name ?? __('Unknown') }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $source->expires_at?->diffForHumans() ?? '—' }}</td>
                                <td class="px-5 py-3 text-right">
                                    <button type="button" wire:click="revokeIp('{{ $source->id }}')"
                                        class="text-xs font-medium text-rose-700 hover:text-rose-900">
                                        {{ __('Revoke') }}
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
@endif

<div>
    <x-fleet-shell
        :title="__('Fleet deploys')"
        :description="__('In-flight, failed, and stagnant deploy activity across the fleet.')"
        :section="__('Deploys')"
    >
    <nav class="mb-6 flex flex-wrap items-center gap-2" aria-label="{{ __('Deploy activity tabs') }}">
        <x-fleet-pill :active="$tab === 'running'" wire:click="setTab('running')">
            {{ __('Running') }} <span class="text-xs opacity-70">({{ $counts['running'] }})</span>
        </x-fleet-pill>
        <x-fleet-pill :active="$tab === 'failed-latest'" wire:click="setTab('failed-latest')">
            {{ __('Failed latest') }} <span class="text-xs opacity-70">({{ $counts['failed-latest'] }})</span>
        </x-fleet-pill>
        <x-fleet-pill :active="$tab === 'stale'" wire:click="setTab('stale')">
            {{ __('Stale') }} <span class="text-xs opacity-70">({{ $counts['stale'] }})</span>
        </x-fleet-pill>
        @if ($tab === 'stale')
            <div class="ml-auto flex items-center gap-2">
                <label for="stale_days" class="text-xs font-medium text-brand-moss">{{ __('Days') }}</label>
                <input id="stale_days" type="number" min="1" wire:model.live.debounce.250ms="staleDays" class="dply-input w-20 py-1.5 text-sm" />
            </div>
        @endif
    </nav>

    @if ($rows === [])
        <x-fleet-empty>
            @if ($tab === 'running')
                {{ __('No deploys are currently running.') }}
            @elseif ($tab === 'failed-latest')
                {{ __('No sites have a failed latest deploy.') }}
            @else
                {{ __('No sites with stale deploys (threshold: :days days).', ['days' => $staleDays]) }}
            @endif
        </x-fleet-empty>
    @else
        <div class="overflow-x-auto rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-brand-sand/30 text-left text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">
                    <tr>
                        <th class="px-4 py-3">{{ __('Site') }}</th>
                        <th class="px-4 py-3">{{ __('Runtime') }}</th>
                        <th class="px-4 py-3">{{ __('When') }}</th>
                        <th class="px-4 py-3">{{ __('Age') }}</th>
                        <th class="px-4 py-3">{{ __('Trigger') }}</th>
                        <th class="px-4 py-3">{{ __('Deploy ID') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/5">
                    @foreach ($rows as $row)
                        <tr @class([
                            'hover:bg-brand-sand/20',
                            'bg-rose-50/50' => $row['severity'] === 'danger',
                            'bg-amber-50/50' => $row['severity'] === 'warning',
                        ])>
                            <td class="px-4 py-3 text-brand-ink">
                                <a href="{{ route('sites.show', ['server' => $row['site']->server_id, 'site' => $row['site']]) }}" wire:navigate class="font-medium hover:text-brand-forest">{{ $row['site']->name }}</a>
                            </td>
                            <td class="px-4 py-3 text-brand-moss">{{ $row['site']->runtime ?: '—' }}</td>
                            <td class="px-4 py-3 text-brand-moss">{{ $row['when'] ?? '—' }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-brand-ink">{{ $row['age_label'] }}</td>
                            <td class="px-4 py-3 text-brand-moss">{{ $row['trigger'] ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <a href="{{ route('sites.deployments.show', ['server' => $row['site']->server_id, 'site' => $row['site'], 'deployment' => $row['deployment_id']]) }}" wire:navigate class="select-all rounded bg-brand-sand/40 px-1.5 py-0.5 font-mono text-[10px] text-brand-moss hover:bg-brand-sand/60 hover:text-brand-ink">{{ $row['deployment_id'] }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @php
        $fleetDeploysCommand = match ($tab) {
            'running' => 'dply fleet:deploys:running',
            'failed-latest' => 'dply fleet:deploys:failed',
            default => 'dply fleet:deploys:stale --days='.$staleDays,
        };
    @endphp
    <x-cli-snippet class="mt-8" :command="$fleetDeploysCommand" />
    </x-fleet-shell>
</div>

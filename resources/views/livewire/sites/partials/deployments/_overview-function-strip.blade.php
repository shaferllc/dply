@php
    $friendlyUrl = $site->serverlessFriendlyUrl();
    $invocationUrl = is_string($site->serverlessConfig()['action_url'] ?? null)
        ? trim((string) $site->serverlessConfig()['action_url'])
        : '';
    $latest = $latestDeployment ?? null;
@endphp

<section class="border-b border-brand-ink/10">
    <x-workspace-panel-head
        dense
        class="border-b border-brand-ink/10"
        icon="heroicon-o-bolt"
        :title="__('Function')"
        :note="__('Live address and the last deploy — open History for the full run list.')"
    >
        <x-slot:actions>
            <button
                type="button"
                wire:click="setTab('{{ \App\Livewire\Sites\DeploymentsList::TAB_DEPLOY }}')"
                class="inline-flex items-center gap-1 rounded-lg bg-brand-ink px-2.5 py-1 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-brand-forest"
            >
                <x-heroicon-o-rocket-launch class="h-3.5 w-3.5" aria-hidden="true" />
                {{ __('Deploy') }}
            </button>
        </x-slot:actions>
    </x-workspace-panel-head>

    <dl class="grid grid-cols-1 gap-px bg-brand-ink/10 sm:grid-cols-3">
        <div class="bg-white px-3 py-2.5 sm:px-4">
            <dt class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Status') }}</dt>
            <dd class="mt-1 text-sm font-semibold text-brand-ink">
                @if ($site->status === \App\Models\Site::STATUS_FUNCTIONS_ACTIVE)
                    <span class="inline-flex items-center gap-1.5 text-emerald-800">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                        {{ __('Live') }}
                    </span>
                @elseif ($site->status === \App\Models\Site::STATUS_FUNCTIONS_FAILED)
                    <span class="inline-flex items-center gap-1.5 text-rose-800">
                        <span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span>
                        {{ __('Failed') }}
                    </span>
                @else
                    <span class="text-brand-moss">{{ $site->status }}</span>
                @endif
            </dd>
        </div>
        <div class="bg-white px-3 py-2.5 sm:px-4">
            <dt class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Last deploy') }}</dt>
            <dd class="mt-1 text-sm text-brand-ink">
                @if ($latest)
                    <span class="font-semibold">{{ $latest->status }}</span>
                    @if ($latest->started_at)
                        <span class="text-brand-moss"> · {{ $latest->started_at->diffForHumans() }}</span>
                    @endif
                @else
                    <span class="text-brand-mist">{{ __('No deploys yet') }}</span>
                @endif
            </dd>
        </div>
        <div class="bg-white px-3 py-2.5 sm:px-4">
            <dt class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Branch') }}</dt>
            <dd class="mt-1 truncate font-mono text-sm text-brand-ink">{{ $site->git_branch ?: '—' }}</dd>
        </div>
    </dl>

    @include('livewire.serverless.partials.function-url-rows', [
        'friendlyUrl' => $friendlyUrl,
        'invocationUrl' => $invocationUrl,
        'pad' => 'px-3 py-2.5 sm:px-4',
        'wrapperClass' => 'border-t border-brand-ink/10',
    ])

    @if (($recentDeployments ?? collect())->isNotEmpty())
        <div class="border-t border-brand-ink/10">
            <div class="flex items-center justify-between gap-3 px-3 py-2 sm:px-4">
                <p class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Recent deploys') }}</p>
                <button
                    type="button"
                    wire:click="setTab('{{ \App\Livewire\Sites\DeploymentsList::TAB_HISTORY }}')"
                    class="text-xs font-semibold text-brand-forest hover:text-brand-sage"
                >{{ __('Full history') }}</button>
            </div>
            <ol class="divide-y divide-brand-ink/10 border-t border-brand-ink/10">
                @foreach ($recentDeployments as $deployment)
                    <li>
                        <a
                            href="{{ \App\Support\Serverless\ServerlessWorkspaceUrl::deploymentShow($site, $deployment) }}"
                            wire:navigate
                            class="flex items-center gap-2 px-3 py-2 text-sm hover:bg-brand-sand/20 sm:px-4"
                        >
                            <span @class([
                                'h-1.5 w-1.5 shrink-0 rounded-full',
                                'bg-emerald-500' => $deployment->status === 'success',
                                'bg-rose-500' => $deployment->status === 'failed',
                                'bg-amber-500' => $deployment->status === 'running',
                                'bg-brand-mist' => ! in_array($deployment->status, ['success', 'failed', 'running'], true),
                            ])></span>
                            <span class="font-semibold text-brand-ink">{{ $deployment->status }}</span>
                            <span class="text-brand-moss">{{ $deployment->trigger ?: '—' }}</span>
                            @if ($deployment->git_sha)
                                <span class="font-mono text-xs text-brand-sage">{{ \Illuminate\Support\Str::limit($deployment->git_sha, 7, '') }}</span>
                            @endif
                            <span class="ml-auto text-xs text-brand-mist">{{ $deployment->started_at?->diffForHumans() }}</span>
                        </a>
                    </li>
                @endforeach
            </ol>
        </div>
    @endif
</section>

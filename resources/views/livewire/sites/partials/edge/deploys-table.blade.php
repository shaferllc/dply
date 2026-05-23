@php
    $compact = $compact ?? false;
    $limit = $compact ? 5 : 20;
    $tableDeployments = $edgeDeployments->take($limit);
@endphp

<section class="dply-card overflow-hidden">
    <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-brand-ink/10 px-6 py-4 sm:px-8">
        <div>
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Deploy history') }}</h3>
            @unless ($compact)
                <p class="mt-0.5 text-sm text-brand-moss">{{ __('Each build publishes static assets to the Edge CDN.') }}</p>
            @endunless
        </div>
        @if ($compact)
            <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'edge-deploys']) }}" wire:navigate class="text-xs font-medium text-brand-sage hover:underline">
                {{ __('View all →') }}
            </a>
        @elseif ($edgeDeployments->count() > 0)
            @can('update', $site)
                <button
                    type="button"
                    wire:click="redeployEdge"
                    wire:loading.attr="disabled"
                    wire:target="redeployEdge"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40"
                >
                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5" wire:loading.remove wire:target="redeployEdge" />
                    {{ __('Redeploy now') }}
                </button>
            @endcan
        @endif
    </div>

    @unless ($compact)
        @can('update', $site)
            <div class="border-b border-brand-ink/10 px-6 py-3 sm:px-8">
                <form wire:submit.prevent="deployEdgeCommit" class="space-y-0">
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="min-w-[16rem] flex-1">
                            <label for="edge_deploy_commit_sha" class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">
                                {{ __('Deploy ref') }}
                            </label>
                            <div class="mt-1 flex gap-2">
                                <input
                                    id="edge_deploy_commit_sha"
                                    type="text"
                                    wire:model="edge_deploy_commit_sha"
                                    placeholder="{{ __('Commit SHA, or browse below') }}"
                                    autocomplete="off"
                                    spellcheck="false"
                                    class="min-w-0 flex-1 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 font-mono text-xs text-brand-ink focus:border-brand-sage focus:ring-1 focus:ring-brand-sage"
                                />
                                <button
                                    type="button"
                                    wire:click="openEdgeDeployRefPicker"
                                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-brand-sand/30 px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/60"
                                >
                                    <x-heroicon-o-magnifying-glass class="h-3.5 w-3.5" />
                                    {{ __('Browse') }}
                                </button>
                            </div>
                        </div>
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="deployEdgeCommit"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40"
                        >
                            {{ __('Deploy') }}
                        </button>
                    </div>
                    <p class="mt-2 text-xs text-brand-moss">{{ __('Pick a commit, branch tip, or tag from your connected Git provider. Re-flips KV if we already built that commit; otherwise rebuilds from that ref.') }}</p>
                    @if ($edge_deploy_ref_picker_open)
                        @include('livewire.sites.partials.edge.deploy-ref-picker')
                    @endif
                </form>
            </div>
        @endcan
    @endunless

    @if ($tableDeployments->isEmpty())
        <div class="px-6 py-8 text-center text-sm text-brand-moss sm:px-8">
            <p>{{ __('No deployments yet.') }}</p>
            @can('update', $site)
                <button type="button" wire:click="redeployEdge" wire:loading.attr="disabled" class="mt-3 text-sm font-medium text-brand-forest hover:underline dark:text-brand-sage">
                    {{ __('Trigger first deploy') }}
                </button>
            @endcan
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/8 text-sm">
                <thead class="bg-brand-sand/30 text-left text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">
                    <tr>
                        <th class="px-6 py-3 sm:px-8">{{ __('Deployment') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3">{{ __('Branch') }}</th>
                        <th class="px-4 py-3">{{ __('Commit') }}</th>
                        <th class="px-4 py-3">{{ __('Published') }}</th>
                        <th class="px-6 py-3 text-right sm:px-8">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/8 text-brand-ink">
                    @foreach ($tableDeployments as $deployment)
                        @php
                            $isActive = $edgeActiveDeploymentId === $deployment->id;
                            $depBadge = match ($deployment->status) {
                                \App\Models\EdgeDeployment::STATUS_LIVE => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300',
                                \App\Models\EdgeDeployment::STATUS_FAILED => 'bg-rose-100 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300',
                                \App\Models\EdgeDeployment::STATUS_BUILDING, \App\Models\EdgeDeployment::STATUS_PUBLISHING => 'bg-sky-100 text-sky-800 dark:bg-sky-950/40 dark:text-sky-300',
                                default => 'bg-brand-sand/60 text-brand-moss',
                            };
                        @endphp
                        <tr wire:key="edge-dep-{{ $deployment->id }}">
                            <td class="px-6 py-3 font-mono text-xs sm:px-8">{{ \Illuminate\Support\Str::limit($deployment->id, 14, '') }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase {{ $depBadge }}">
                                    {{ str_replace('_', ' ', (string) $deployment->status) }}
                                </span>
                                @if ($isActive)
                                    <span class="ms-1 text-[10px] font-semibold text-emerald-700 dark:text-emerald-400">{{ __('Current') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $deployment->git_branch ?? $edgeBranch }}</td>
                            <td class="px-4 py-3 font-mono text-xs">
                                @if ($deployment->git_commit)
                                    <span title="{{ $deployment->git_commit }}">{{ substr($deployment->git_commit, 0, 7) }}</span>
                                @else
                                    <span class="text-brand-mist">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs text-brand-moss">
                                {{ $deployment->published_at?->diffForHumans() ?? ($deployment->created_at?->diffForHumans() ?? '—') }}
                                @if ($deployment->pruned_at)
                                    <span class="ms-1 inline-flex rounded-full bg-brand-sand/60 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-mist" title="{{ __('R2 artifacts deleted by retention policy.') }}">{{ __('Pruned') }}</span>
                                @endif
                            </td>
                            <td class="px-6 py-3 text-right text-xs sm:px-8">
                                @if (! $isActive && ($deployment->status === \App\Models\EdgeDeployment::STATUS_LIVE || $deployment->status === \App\Models\EdgeDeployment::STATUS_SUPERSEDED) && $deployment->storage_prefix !== null)
                                    @can('update', $site)
                                        <button type="button" wire:click="rollbackEdgeDeployment('{{ $deployment->id }}')" class="font-medium text-brand-forest hover:underline dark:text-brand-sage">
                                            {{ __('Roll back') }}
                                        </button>
                                    @endcan
                                @elseif ($deployment->storage_prefix === null && $deployment->git_commit)
                                    @can('update', $site)
                                        <button type="button" wire:click="$set('edge_deploy_commit_sha', '{{ $deployment->git_commit }}')" class="font-medium text-brand-moss hover:underline" title="{{ __('Fill the deploy-commit input with this SHA so you can rebuild from it.') }}">
                                            {{ __('Rebuild') }}
                                        </button>
                                    @endcan
                                @else
                                    <span class="text-brand-mist">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

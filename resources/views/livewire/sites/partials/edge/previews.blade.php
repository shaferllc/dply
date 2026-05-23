@php
    $previews = $edgeIsPreviewChild ? collect() : \App\Actions\Edge\CreateEdgePreviewSite::listForParent($site);
@endphp

<section class="dply-card overflow-hidden">
    <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
        <h3 class="text-base font-semibold text-brand-ink">{{ __('Branch previews') }}</h3>
        <p class="mt-0.5 text-sm text-brand-moss">{{ __('Pull request previews deploy to unique Edge URLs. Tear down when the PR closes or manually from here.') }}</p>
    </div>

    @if ($previews->isEmpty())
        <div class="px-6 py-8 text-center text-sm text-brand-moss sm:px-8">
            <x-heroicon-o-sparkles class="mx-auto h-8 w-8 text-brand-mist" />
            <p class="mt-3 font-medium text-brand-ink">{{ __('No active previews') }}</p>
            <p class="mt-1">{{ __('Open a pull request against :branch and configure the GitHub webhook to create preview deployments.', ['branch' => $edgeBranch]) }}</p>
            <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'edge-build']) }}" wire:navigate class="mt-3 inline-block text-sm font-medium text-brand-forest hover:underline dark:text-brand-sage">
                {{ __('View webhook setup →') }}
            </a>
        </div>
    @else
        <ul class="divide-y divide-brand-ink/8">
            @foreach ($previews as $preview)
                @php
                    $previewMeta = $preview->edgeMeta();
                    $previewBranch = (string) ($previewMeta['preview_branch'] ?? '—');
                    $previewPrNumber = $previewMeta['preview_pr_number'] ?? null;
                    $previewUrl = $preview->edgeLiveUrl();
                @endphp
                <li class="flex flex-wrap items-center justify-between gap-4 px-6 py-4 sm:px-8">
                    <div class="min-w-0">
                        <p class="font-mono text-sm font-medium text-brand-ink">
                            {{ $previewBranch }}
                            @if (is_int($previewPrNumber) || (is_string($previewPrNumber) && $previewPrNumber !== ''))
                                <span class="ms-1 text-xs font-normal text-brand-moss">· PR #{{ $previewPrNumber }}</span>
                            @endif
                        </p>
                        @if ($previewUrl)
                            <a href="{{ $previewUrl }}" target="_blank" rel="noopener noreferrer" class="mt-1 inline-flex items-center gap-1 font-mono text-xs text-brand-forest hover:underline dark:text-brand-sage">
                                {{ $previewUrl }}
                                <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" />
                            </a>
                        @endif
                    </div>
                    @can('update', $site)
                        <button type="button" wire:click="tearDownEdgePreview('{{ $preview->id }}')" class="text-xs font-medium text-rose-700 hover:text-rose-900 dark:text-rose-400">
                            {{ __('Tear down') }}
                        </button>
                    @endcan
                </li>
            @endforeach
        </ul>
    @endif
</section>

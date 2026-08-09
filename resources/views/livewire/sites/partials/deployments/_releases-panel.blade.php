@php
    $btnOutline = 'dply-btn dply-btn-xs dply-btn-outline';
    $releaseCount = $releases?->total() ?? 0;
@endphp

<section class="border-b border-brand-ink/10">
    <x-workspace-panel-head
        dense
        class="border-b border-brand-ink/10"
        icon="heroicon-o-archive-box"
        :title="__('Releases & rollback')"
        :count="trans_choice('{0} none|{1} :count|[2,*] :count', $releaseCount, ['count' => $releaseCount])"
        :note="__('Atomic folders on disk. Active = current symlink; rollback points current at an older folder.')"
    />

    @if ($releases === null || $releases->isEmpty())
        <p class="px-3 py-4 text-center text-xs text-brand-moss sm:px-4">
            {{ __('No releases on disk yet. Run a deploy with the atomic strategy and it will appear here.') }}
        </p>
    @else
        <ul class="divide-y divide-brand-ink/10">
            @foreach ($releases as $rel)
                <li class="flex items-center justify-between gap-2.5 px-3 py-2 transition-colors hover:bg-brand-sand/15 sm:px-4" wire:key="release-{{ $rel->id }}">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-1.5 font-mono text-xs text-brand-ink">
                            {{ $rel->folder }}
                            @if ($rel->is_active)
                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-[0.12em] text-emerald-800 ring-1 ring-inset ring-emerald-200">{{ __('Active') }}</span>
                            @endif
                        </p>
                        <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-brand-mist">
                            @if ($rel->git_sha)
                                <span class="font-mono">{{ \Illuminate\Support\Str::limit($rel->git_sha, 7, '') }}</span>
                            @endif
                            @if ($rel->created_at)
                                <span title="{{ $rel->created_at->toDayDateTimeString() }}">{{ $rel->created_at->diffForHumans() }}</span>
                            @endif
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-1.5">
                        <button
                            type="button"
                            wire:click="openReleaseInfo('{{ $rel->id }}')"
                            class="inline-flex h-7 w-7 items-center justify-center rounded-md border border-brand-ink/15 bg-white text-brand-moss shadow-sm transition-colors hover:bg-brand-sand/40 hover:text-brand-ink"
                            title="{{ __('Release details') }}"
                            aria-label="{{ __('More info about release :folder', ['folder' => $rel->folder]) }}"
                        >
                            <x-heroicon-o-information-circle class="h-4 w-4" aria-hidden="true" />
                        </button>
                        @if (! $rel->is_active)
                            <button
                                type="button"
                                wire:click="confirmRollbackRelease('{{ $rel->id }}')"
                                class="{{ $btnOutline }}"
                            >
                                <x-heroicon-o-arrow-uturn-left class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ __('Rollback') }}
                            </button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        @if ($releases->hasPages())
            <div class="border-t border-brand-ink/10 bg-white px-3 py-2 sm:px-4">
                {{ $releases->links() }}
            </div>
        @endif
    @endif
</section>

<x-modal name="release-info" maxWidth="lg" overlayClass="bg-brand-ink/40" focusable>
    @php $info = $releaseInfo; @endphp
    <div class="relative border-b border-brand-ink/10 px-3 py-2.5 sm:px-4">
        <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Release details') }}</p>
        <h2 class="mt-1 flex flex-wrap items-center gap-2 font-mono text-lg font-semibold text-brand-ink">
            {{ $info['folder'] ?? '—' }}
            @if ($info && ($info['is_active'] ?? false))
                <span class="inline-flex items-center rounded-full bg-emerald-50 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-[0.12em] text-emerald-800 ring-1 ring-inset ring-emerald-200">{{ __('Active') }}</span>
            @endif
        </h2>
        <p class="mt-1 text-xs text-brand-moss">{{ __('On-disk release folder kept for zero-downtime deploys and rollback.') }}</p>
        <button
            type="button"
            wire:click="closeReleaseInfo"
            x-on:click="$dispatch('close')"
            class="absolute right-3 top-3 inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist transition-colors hover:bg-brand-sand/40 hover:text-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-sage/40"
            aria-label="{{ __('Close') }}"
        >
            <x-heroicon-o-x-mark class="h-5 w-5" />
        </button>
    </div>

    @if ($info)
        <div class="space-y-3 px-3 py-2.5 sm:px-4">
            @php
                $facts = [
                    ['label' => __('Folder'), 'value' => $info['folder'], 'class' => 'truncate font-mono text-xs text-brand-ink'],
                    ['label' => __('Status'), 'value' => $info['is_active'] ? __('Active (served via current)') : __('Inactive'), 'class' => $info['is_active'] ? 'text-xs font-semibold text-emerald-700' : 'text-xs text-brand-ink'],
                ];
                if ($info['created_at_human']) {
                    $facts[] = ['label' => __('Created'), 'value' => $info['created_at_human'], 'class' => 'text-xs text-brand-ink', 'title' => $info['created_at']];
                }
                $oddCount = count($facts) % 2 === 1;
            @endphp
            <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl bg-brand-ink/[0.06] ring-1 ring-inset ring-brand-ink/10">
                @foreach ($facts as $fact)
                    <div @class(['bg-white px-3 py-2.5', 'col-span-2' => $oddCount && $loop->last])>
                        <dt class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $fact['label'] }}</dt>
                        <dd class="mt-0.5 {{ $fact['class'] }}" @isset($fact['title']) title="{{ $fact['title'] }}" @endisset>{{ $fact['value'] }}</dd>
                    </div>
                @endforeach
            </dl>

            <div>
                <p class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('On-disk path') }}</p>
                <code class="mt-1 block break-all rounded-lg bg-brand-sand/40 px-3 py-2 font-mono text-xs text-brand-ink ring-1 ring-inset ring-brand-ink/10">{{ $info['path'] }}</code>
                @if ($info['is_active'])
                    <p class="mt-1.5 text-xs text-brand-moss">
                        {{ __('Live symlink') }}
                        <span class="font-mono text-brand-ink">{{ $info['current_symlink'] }}</span>
                        →
                        <span class="font-mono text-brand-ink">{{ $info['path'] }}</span>
                    </p>
                @else
                    <p class="mt-1.5 text-xs text-brand-moss">{{ __('Rollback swaps the current symlink to this folder without a new deploy.') }}</p>
                @endif
            </div>

            @if ($info['git_sha'])
                <div x-data="{ copied: false }">
                    <p class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Git SHA') }}</p>
                    <div class="mt-1 flex items-center gap-2">
                        <code class="min-w-0 flex-1 truncate rounded-lg bg-brand-sand/40 px-3 py-2 font-mono text-xs text-brand-ink ring-1 ring-inset ring-brand-ink/10">{{ $info['git_sha'] }}</code>
                        <button
                            type="button"
                            x-on:click="navigator.clipboard.writeText(@js($info['git_sha'])); copied = true; setTimeout(() => copied = false, 1500)"
                            class="{{ $btnOutline }} shrink-0"
                        >
                            <template x-if="! copied"><span class="inline-flex items-center gap-1"><x-heroicon-o-clipboard-document class="h-3.5 w-3.5" /> {{ __('Copy') }}</span></template>
                            <template x-if="copied"><span class="inline-flex items-center gap-1 text-emerald-700"><x-heroicon-o-check class="h-3.5 w-3.5" /> {{ __('Copied') }}</span></template>
                        </button>
                    </div>
                </div>
            @endif
        </div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-brand-ink/10 bg-brand-sand/25 px-3 py-2 sm:px-4">
        <div class="flex flex-wrap items-center gap-3">
            @if ($info && ($info['deployment_id'] ?? null))
                <a
                    href="{{ route('sites.deployments.show', ['server' => $server, 'site' => $site, 'deployment' => $info['deployment_id']]) }}"
                    wire:navigate
                    class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-forest hover:underline"
                >
                    <x-heroicon-o-document-text class="h-3.5 w-3.5" /> {{ __('Open deployment') }}
                </a>
            @endif
            @if ($info && ! ($info['is_active'] ?? true))
                <button
                    type="button"
                    wire:click="confirmRollbackRelease('{{ $info['id'] }}')"
                    x-on:click="$dispatch('close')"
                    class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-forest hover:underline"
                >
                    <x-heroicon-o-arrow-uturn-left class="h-3.5 w-3.5" /> {{ __('Rollback to this release') }}
                </button>
            @endif
        </div>
        <x-secondary-button type="button" wire:click="closeReleaseInfo" x-on:click="$dispatch('close')">{{ __('Close') }}</x-secondary-button>
    </div>
</x-modal>

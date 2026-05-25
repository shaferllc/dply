<div class="max-w-5xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Infrastructure'), 'href' => route('infrastructure.index'), 'icon' => 'rectangle-group'],
        ['label' => __('Edge'), 'href' => route('edge.index'), 'icon' => 'globe-alt'],
        ['label' => $site->name, 'href' => route('sites.show', ['server' => $server, 'site' => $site]), 'icon' => 'globe-alt'],
        ['label' => __('Comments'), 'icon' => 'chat-bubble-left-right'],
    ]" />

    <x-page-header
        title="{{ __('Preview comments') }}"
        :description="__('Reviewer notes left on this preview deploy.')"
        flush
        class="mt-5"
    >
        <x-slot name="leading">
            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                <x-heroicon-o-chat-bubble-left-right class="h-7 w-7 text-brand-ink" />
            </span>
        </x-slot>
    </x-page-header>

    <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50/60 px-4 py-3 text-xs text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-200">
        {{ __('The on-page comment widget (inject-from-Worker) is not shipped yet. Comments added here come from the dashboard form below — once the widget lands, reviewer comments from inside the preview appear in the same list.') }}
    </div>

    @can('update', $site)
        <section class="dply-card mt-6 overflow-hidden">
            <div class="border-b border-brand-ink/10 px-6 py-4">
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Add a comment') }}</h3>
            </div>
            <form wire:submit.prevent="addComment" class="space-y-4 px-6 py-5">
                <label class="block">
                    <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Path') }}</span>
                    <input
                        type="text"
                        wire:model="newCommentUrlPath"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="/"
                        class="mt-1.5 w-full max-w-sm rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                    />
                    @error('newCommentUrlPath')
                        <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                    @enderror
                </label>
                <label class="block">
                    <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Body') }}</span>
                    <textarea
                        wire:model="newCommentBody"
                        rows="3"
                        class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                    ></textarea>
                    @error('newCommentBody')
                        <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                    @enderror
                </label>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="addComment"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90"
                >
                    {{ __('Add comment') }}
                </button>
            </form>
        </section>
    @endcan

    <section class="dply-card mt-6 overflow-hidden">
        <div class="border-b border-brand-ink/10 px-6 py-4">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('All comments') }} <span class="ml-2 text-xs font-normal text-brand-moss">{{ $comments->count() }}</span></h3>
        </div>
        @if ($comments->isEmpty())
            <div class="px-6 py-12 text-center">
                <x-heroicon-o-chat-bubble-bottom-center-text class="mx-auto h-8 w-8 text-brand-moss/50" />
                <p class="mt-3 text-sm text-brand-moss">{{ __('No comments yet on this preview.') }}</p>
            </div>
        @else
            <ul class="divide-y divide-brand-ink/10">
                @foreach ($comments as $comment)
                    <li class="px-6 py-4">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-xs text-brand-moss">
                                    <span class="font-semibold text-brand-ink">{{ $comment->authorDisplayName() }}</span>
                                    <span class="font-mono">{{ $comment->url_path }}</span>
                                    @if ($comment->viewport_width)
                                        <span>{{ $comment->viewport_width }}px</span>
                                    @endif
                                    @if ($comment->selector)
                                        <span class="font-mono opacity-70">{{ $comment->selector }}</span>
                                    @endif
                                    <span class="opacity-60">{{ $comment->created_at?->diffForHumans() }}</span>
                                </div>
                                <p class="mt-2 whitespace-pre-line text-sm text-brand-ink">{{ $comment->body }}</p>
                                @if ($comment->resolved_at)
                                    <p class="mt-2 text-xs text-emerald-700 dark:text-emerald-300">
                                        {{ __('Resolved') }}
                                        @if ($comment->resolvedBy)
                                            {{ __('by :name', ['name' => $comment->resolvedBy->name ?: $comment->resolvedBy->email]) }}
                                        @endif
                                        {{ $comment->resolved_at?->diffForHumans() }}
                                    </p>
                                @endif
                            </div>
                            @can('update', $site)
                                <div class="flex shrink-0 items-center gap-2">
                                    <button
                                        type="button"
                                        wire:click="toggleResolved('{{ $comment->id }}')"
                                        class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-medium text-brand-moss hover:bg-brand-sand/40"
                                    >
                                        {{ $comment->resolved_at ? __('Reopen') : __('Resolve') }}
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="deleteComment('{{ $comment->id }}')"
                                        wire:confirm="{{ __('Delete this comment?') }}"
                                        class="inline-flex items-center gap-1 rounded-lg border border-rose-200 bg-white px-2.5 py-1 text-xs font-medium text-rose-900 hover:bg-rose-50 dark:border-rose-900/40 dark:bg-zinc-900 dark:text-rose-300"
                                    >
                                        {{ __('Delete') }}
                                    </button>
                                </div>
                            @endcan
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>

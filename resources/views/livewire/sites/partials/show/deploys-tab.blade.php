                <section class="dply-card overflow-hidden">
                    <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:px-7">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-rocket-launch class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Deploy') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Deploy this site') }}</h3>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Run a deploy now (synchronous) or queue one for the worker. Repository and runtime config live in') }}
                                <a href="{{ route('sites.settings', ['server' => $server, 'site' => $site, 'section' => 'deploy']) }}" wire:navigate class="font-medium text-brand-ink hover:underline">{{ __('deploy settings') }}</a>.
                            </p>
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-2 sm:ml-auto">
                            <button type="button" wire:click="deployNow" wire:loading.attr="disabled" wire:target="deployNow" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:opacity-60">
                                <x-heroicon-o-rocket-launch class="h-3.5 w-3.5" wire:loading.remove wire:target="deployNow" />
                                <span wire:loading wire:target="deployNow"><x-spinner variant="white" size="sm" /></span>
                                <span wire:loading.remove wire:target="deployNow">{{ __('Deploy now') }}</span>
                                <span wire:loading wire:target="deployNow">{{ __('Deploying…') }}</span>
                            </button>
                            <button type="button" wire:click="queueDeploy" wire:loading.attr="disabled" wire:target="queueDeploy" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50">
                                <x-heroicon-o-queue-list class="h-3.5 w-3.5" />
                                {{ __('Queue deploy') }}
                            </button>
                        </div>
                    </div>
                </section>

                @if ($atomicReleases)
                    <section class="dply-card overflow-hidden">
                        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                <x-heroicon-o-arrow-path class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Releases') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Releases & rollback') }}</h3>
                            </div>
                            <span class="ml-auto shrink-0 self-center text-xs text-brand-mist">{{ trans_choice('{0} no releases|{1} :count release|[2,*] :count releases', $site->releases->count(), ['count' => $site->releases->count()]) }}</span>
                        </div>
                        @if ($site->releases->isEmpty())
                            <p class="px-6 py-6 text-sm text-brand-mist sm:px-8">{{ __('No recorded releases yet. Deploy once with the atomic strategy.') }}</p>
                        @else
                            <ul class="divide-y divide-brand-ink/8">
                                @foreach ($site->releases as $rel)
                                    <li class="flex items-center justify-between gap-3 px-6 py-3 sm:px-8">
                                        <div class="min-w-0">
                                            <p class="font-mono text-xs text-brand-ink">{{ $rel->folder }}
                                                @if ($rel->is_active)<span class="ml-2 inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-emerald-800">{{ __('Active') }}</span>@endif
                                            </p>
                                            @if ($rel->git_sha)
                                                <p class="font-mono text-[11px] text-brand-mist">{{ $rel->git_sha }}</p>
                                            @endif
                                        </div>
                                        @if (! $rel->is_active)
                                            <button type="button" wire:click="confirmRollbackRelease('{{ $rel->id }}')" class="text-xs font-medium text-brand-sage hover:underline">{{ __('Rollback') }}</button>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @endif

                <section class="dply-card overflow-hidden" wire:poll.10s>
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-rocket-launch class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Deployments') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Recent deployments') }}</h3>
                        </div>
                        @if ($site->workspace)
                            @feature('surface.projects')
                                <a href="{{ route('projects.delivery', $site->workspace) }}" wire:navigate class="ml-auto shrink-0 self-center text-xs font-medium text-brand-sage hover:underline">{{ __('Project delivery') }}</a>
                            @endfeature
                        @endif
                    </div>
                    <div class="px-6 py-5 sm:px-8">
                        @if ($deploymentConsoles->isEmpty())
                            <p class="text-sm text-brand-mist">{{ __('No deployments yet.') }}</p>
                        @else
                            <div class="space-y-4">
                                @foreach ($deploymentConsoles as $deploymentConsole)
                                    @include('livewire.partials.deployment-activity-console', [
                                        'title' => $deploymentConsole['title'],
                                        'meta' => $deploymentConsole['meta'],
                                        'transcript' => \Illuminate\Support\Str::limit($deploymentConsole['transcript'], 8000),
                                        'maxHeight' => '20rem',
                                    ])
                                @endforeach
                            </div>
                        @endif
                    </div>
                </section>

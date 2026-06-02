<div>
    <x-server-workspace-shell :server="$server" :active="'sites'">
        <div class="mx-auto max-w-3xl space-y-8 py-8">
            <header class="space-y-1">
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">
                    {{ __('New site') }}
                </p>
                <h1 class="text-2xl font-semibold text-brand-ink">{{ __('Custom — headless workload') }}</h1>
                <p class="text-sm text-brand-moss">
                    {{ __('Code + deploy pipeline on this server. No domain, no webserver, no SSL. Use for daemons, workers, microservices on private ports, or any repo you want dply to clone and run scripts against.') }}
                </p>
            </header>

            <form wire:submit="store" class="space-y-8">
                <section class="dply-card overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-command-line class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Details') }}</p>
                            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Site details') }}</h2>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Leave the repository fields blank for a no-repo deploy target (CI rsyncs code, dply runs your script).') }}</p>
                        </div>
                    </div>
                    <div class="px-6 py-6 sm:px-7">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <x-input-label for="custom_name" :value="__('Name')" />
                            <x-text-input
                                id="custom_name"
                                type="text"
                                wire:model="name"
                                class="mt-1 block w-full font-mono text-base"
                                placeholder="worker-queue"
                                required
                                autocomplete="off"
                            />
                            <p class="mt-1 text-xs text-brand-mist">{{ __('Letters, numbers, dashes, underscores, dots. Used for the slug and deploy directory.') }}</p>
                            <x-input-error :messages="$errors->get('name')" class="mt-1" />
                        </div>

                        <div class="sm:col-span-2">
                            <x-input-label for="custom_git_url" :value="__('Git repository URL (optional)')" />
                            <x-text-input
                                id="custom_git_url"
                                type="text"
                                wire:model.live.debounce.500ms="git_repository_url"
                                class="mt-1 block w-full font-mono text-sm"
                                placeholder="git@github.com:you/worker.git"
                                autocomplete="off"
                            />
                            <p class="mt-1 text-xs text-brand-mist">{{ __('Leave blank for no-repo mode. URL presence determines the mode and cannot change later.') }}</p>
                            <x-input-error :messages="$errors->get('git_repository_url')" class="mt-1" />
                        </div>

                        <div @class(['transition-opacity', 'opacity-40 pointer-events-none' => trim($git_repository_url) === ''])>
                            <x-input-label for="custom_branch" :value="__('Branch')" />
                            <x-text-input
                                id="custom_branch"
                                type="text"
                                wire:model="git_branch"
                                class="mt-1 block w-full font-mono text-sm"
                                placeholder="main"
                                autocomplete="off"
                            />
                            <x-input-error :messages="$errors->get('git_branch')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="custom_user" :value="__('System user override (optional)')" />
                            <x-text-input
                                id="custom_user"
                                type="text"
                                wire:model="system_user_override"
                                class="mt-1 block w-full font-mono text-sm"
                                placeholder="{{ $server->ssh_user }}"
                                autocomplete="off"
                            />
                            <p class="mt-1 text-xs text-brand-mist">{{ __('Defaults to the server\'s SSH user. Set a different user for process isolation.') }}</p>
                            <x-input-error :messages="$errors->get('system_user_override')" class="mt-1" />
                        </div>
                    </div>
                    </div>
                </section>

                <section class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-5 text-sm text-brand-moss">
                    <p class="font-semibold text-brand-ink">{{ __('What happens next') }}</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li>{{ __('dply creates the deploy directory and a stub deploy script you can edit.') }}</li>
                        @if (trim($git_repository_url) !== '')
                            <li>{{ __('The repo is cloned to the deploy directory.') }}</li>
                        @else
                            <li>{{ __('No code is fetched. Push code to the server yourself (rsync, scp, etc.) and trigger deploys via webhook or the Deploy button.') }}</li>
                        @endif
                        <li>{{ __('Cron and Workers tabs are available for your processes.') }}</li>
                        <li>{{ __('No nginx vhost, no SSL, no domain are created.') }}</li>
                    </ul>
                </section>

                <footer class="flex items-center justify-between border-t border-brand-ink/10 pt-6">
                    <a href="{{ route('sites.create', $server) }}" wire:navigate class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-moss hover:text-brand-ink">
                        <x-heroicon-o-arrow-left class="h-4 w-4" />
                        {{ __('Back') }}
                    </a>
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="store"
                        class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-brand-ink px-6 text-sm font-semibold text-brand-cream shadow-md shadow-brand-ink/15 transition-colors hover:bg-brand-forest disabled:cursor-wait disabled:opacity-60"
                    >
                        <span wire:loading.remove wire:target="store">{{ __('Create custom site') }}</span>
                        <span wire:loading wire:target="store" class="inline-flex items-center gap-2">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Provisioning…') }}
                        </span>
                        <x-heroicon-o-arrow-right wire:loading.remove wire:target="store" class="h-4 w-4" />
                    </button>
                </footer>
            </form>
        </div>
    </x-server-workspace-shell>
</div>

{{-- Nested inside Settings Danger merged card — strips, no second page card. --}}
<div class="min-w-0">
    {{-- Suspend public traffic --}}
    @if ($this->shouldAutoReapplyManagedWebserverConfig())
        <section class="border-b border-brand-ink/10">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-4 sm:px-6">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-amber-100 text-amber-700 ring-amber-200">
                    <x-heroicon-o-pause-circle class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-700">{{ __('Traffic') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Suspend public site') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Visitors will see a suspended page instead of your app until you resume. Deploy hooks and Dply settings still work.') }}</p>
                </div>
            </div>

            <div class="px-5 py-5 sm:px-6">
                @if ($site->isSuspended())
                    <p class="text-sm font-medium text-amber-900">{{ __('This site is currently suspended.') }}</p>
                    @can('update', $site)
                        <button type="button" wire:click="resumeSite" wire:loading.attr="disabled" wire:target="resumeSite" class="mt-3 inline-flex items-center gap-1.5 rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-semibold text-amber-900 shadow-sm transition hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-60">
                            <x-heroicon-o-play class="h-4 w-4" wire:loading.remove wire:target="resumeSite" aria-hidden="true" />
                            <x-spinner wire:loading wire:target="resumeSite" variant="amber" size="sm" />
                            <span wire:loading.remove wire:target="resumeSite">{{ __('Resume site') }}</span>
                            <span wire:loading wire:target="resumeSite">{{ __('Resuming…') }}</span>
                        </button>
                    @endcan
                @else
                    <div class="space-y-2">
                        <label for="settings_suspended_message" class="block text-xs font-medium text-brand-moss">{{ __('Public message (optional)') }}</label>
                        <textarea id="settings_suspended_message" wire:model="settings_suspended_message" rows="2" maxlength="500" class="block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:ring-brand-sage/30" placeholder="{{ __('Shown on the suspended page — e.g. billing or contact info.') }}"></textarea>
                        @error('settings_suspended_message')
                            <p class="text-sm text-rose-700">{{ $message }}</p>
                        @enderror
                    </div>
                    @can('update', $site)
                        <button type="button" wire:click="confirmSuspendSite" wire:loading.attr="disabled" wire:target="confirmSuspendSite" class="mt-3 inline-flex items-center gap-1.5 rounded-lg border border-amber-400 bg-amber-100 px-3 py-1.5 text-xs font-semibold text-amber-900 shadow-sm transition hover:bg-amber-200 disabled:cursor-not-allowed disabled:opacity-60">
                            <x-heroicon-o-pause class="h-4 w-4" wire:loading.remove wire:target="confirmSuspendSite" aria-hidden="true" />
                            <x-spinner wire:loading wire:target="confirmSuspendSite" variant="amber" size="sm" />
                            <span wire:loading.remove wire:target="confirmSuspendSite">{{ __('Suspend site') }}</span>
                            <span wire:loading wire:target="confirmSuspendSite">{{ __('Suspending…') }}</span>
                        </button>
                    @endcan
                @endif
            </div>
        </section>
    @else
        <div class="border-b border-brand-ink/10 px-5 py-5 sm:px-6">
            <p class="text-sm leading-relaxed text-brand-moss">{{ __('Suspending HTTP traffic from the edge is only available for VM sites with managed web server configuration (not serverless, Docker, or Kubernetes runtimes).') }}</p>
        </div>
    @endif

    {{-- Clone site --}}
    @can('clone', $site)
        <section class="border-b border-brand-ink/10">
            <div class="flex flex-col gap-4 px-5 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-6">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-sky-50 text-sky-700 ring-sky-200">
                        <x-heroicon-o-document-duplicate class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Copy') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Clone site') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Create a copy on another server in your organization with a new domain. Databases, SSL certificates, environment files, and custom Nginx extra snippets are not copied.') }}</p>
                    </div>
                </div>
                <a href="{{ route('sites.clone', [$server, $site]) }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 self-start rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                    <x-heroicon-o-document-duplicate class="h-4 w-4" aria-hidden="true" />
                    {{ __('Clone site') }}
                </a>
            </div>
        </section>

        @feature('workspace.site_promote')
        @if ($server->isVmHost())
            <section class="border-b border-brand-ink/10">
                <div class="flex flex-col gap-4 px-5 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-6">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-brand-sage/15 text-brand-forest ring-brand-sage/25">
                            <x-heroicon-o-arrow-right-circle class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-forest">{{ __('Standby') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Promote to another server') }}</h3>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Copy to a standby server on a preview hostname first — smoke-test deploys, copy env vars, then cut over production DNS using the in-app playbook.') }}</p>
                        </div>
                    </div>
                    <a href="{{ route('sites.promote', [$server, $site]) }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 self-start rounded-lg border border-brand-sage/30 bg-white px-3 py-1.5 text-xs font-semibold text-brand-forest shadow-sm transition hover:bg-brand-sage/10">
                        <x-heroicon-o-arrow-right-circle class="h-4 w-4" aria-hidden="true" />
                        {{ __('Promote to server') }}
                    </a>
                </div>
            </section>
        @endif
        @endfeature
    @endcan

    {{-- Delete site --}}
    @can('delete', $site)
        <section class="border-b border-rose-200">
            <div @class([
                'flex flex-col gap-4 bg-rose-50/60 px-5 py-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-6',
                'border-b border-rose-200' => $site->scheduled_deletion_at,
            ])>
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-rose-100 text-rose-700 ring-rose-200">
                        <x-heroicon-o-trash class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-700">{{ __('Destructive') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-rose-900">{{ __('Delete site') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Removes the site from Dply. A background job removes the vhost, optional releases/repo/cert, supervisor rows tied to this site, the deploy SSH key, and re-syncs the server crontab. Schedule the removal for later if you want a grace window.') }}</p>
                    </div>
                </div>
                @unless ($site->scheduled_deletion_at)
                    <button type="button" wire:click="openRemoveSiteModal" class="inline-flex shrink-0 items-center gap-1.5 self-start rounded-lg border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-800 shadow-sm transition hover:bg-rose-100">
                        <x-heroicon-o-trash class="h-4 w-4" aria-hidden="true" />
                        {{ __('Delete site') }}
                    </button>
                @endunless
            </div>

            @if ($site->scheduled_deletion_at)
                <div class="px-5 py-4 sm:px-6">
                    <div class="flex flex-wrap items-center gap-3 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                        <x-heroicon-o-clock class="h-4 w-4 shrink-0" aria-hidden="true" />
                        <span class="flex-1">
                            {{ __('Scheduled for removal at :time.', ['time' => $site->scheduled_deletion_at->copy()->timezone(config('app.timezone'))->toDayDateTimeString()]) }}
                        </span>
                        <button type="button" wire:click="cancelScheduledSiteRemoval" wire:loading.attr="disabled" wire:target="cancelScheduledSiteRemoval" class="inline-flex items-center gap-1 rounded-md border border-amber-300 bg-white px-2 py-1 font-semibold hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-60">
                            <x-heroicon-o-x-mark class="h-4 w-4" wire:loading.remove wire:target="cancelScheduledSiteRemoval" aria-hidden="true" />
                            <x-spinner wire:loading wire:target="cancelScheduledSiteRemoval" variant="amber" size="sm" />
                            <span wire:loading.remove wire:target="cancelScheduledSiteRemoval">{{ __('Cancel scheduled removal') }}</span>
                            <span wire:loading wire:target="cancelScheduledSiteRemoval">{{ __('Cancelling…') }}</span>
                        </button>
                    </div>
                </div>
            @endif
        </section>
    @endcan

    @include('livewire.sites.partials.remove-site-modal', [
        'open' => $showRemoveSiteModal ?? false,
        'siteName' => $site->name,
    ])

    <div class="bg-brand-sand/25 px-5 py-4 sm:px-6">
        <x-cli-snippet :commands="[
            ['label' => __('Tear down systemd units'), 'command' => 'dply sites:systemd:teardown '.$site->slug],
            ['label' => __('Re-sync systemd units'), 'command' => 'dply sites:systemd:redeploy '.$site->slug],
        ]" />
    </div>
</div>

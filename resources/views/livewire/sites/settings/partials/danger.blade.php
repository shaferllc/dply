{{-- Nested inside Settings Danger merged card — compact hairline action strips. --}}
<div class="min-w-0">
    {{-- Suspend public traffic --}}
    @if ($this->shouldAutoReapplyManagedWebserverConfig())
        <section class="border-b border-brand-ink/10">
            <div class="flex flex-col gap-2.5 px-3 py-2.5 sm:flex-row sm:items-start sm:justify-between sm:gap-4 sm:px-4">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-1.5">
                        <x-heroicon-o-pause-circle class="h-4 w-4 shrink-0 text-amber-700" aria-hidden="true" />
                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Suspend public site') }}</h3>
                        @if ($site->isSuspended())
                            <span class="rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-900 ring-1 ring-inset ring-amber-200">{{ __('Suspended') }}</span>
                        @endif
                    </div>
                    <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ __('Visitors see a suspended page until you resume. Deploys and settings still work.') }}</p>

                    @unless ($site->isSuspended())
                        <div class="mt-2 space-y-1">
                            <label for="settings_suspended_message" class="block text-[11px] font-medium text-brand-moss">{{ __('Public message (optional)') }}</label>
                            <textarea id="settings_suspended_message" wire:model="settings_suspended_message" rows="2" maxlength="500" class="block w-full rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:ring-brand-sage/30" placeholder="{{ __('Shown on the suspended page — e.g. billing or contact info.') }}"></textarea>
                            @error('settings_suspended_message')
                                <p class="text-xs text-rose-700">{{ $message }}</p>
                            @enderror
                        </div>
                    @endunless
                </div>

                @can('update', $site)
                    @if ($site->isSuspended())
                        <button type="button" wire:click="resumeSite" wire:loading.attr="disabled" wire:target="resumeSite" class="inline-flex shrink-0 items-center gap-1.5 self-start rounded-lg border border-amber-300 bg-white px-2.5 py-1 text-[11px] font-semibold text-amber-900 shadow-sm transition hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-60">
                            <x-heroicon-o-play class="h-3.5 w-3.5" wire:loading.remove wire:target="resumeSite" aria-hidden="true" />
                            <x-spinner wire:loading wire:target="resumeSite" variant="amber" size="sm" />
                            <span wire:loading.remove wire:target="resumeSite">{{ __('Resume') }}</span>
                            <span wire:loading wire:target="resumeSite">{{ __('Resuming…') }}</span>
                        </button>
                    @else
                        <button type="button" wire:click="confirmSuspendSite" wire:loading.attr="disabled" wire:target="confirmSuspendSite" class="inline-flex shrink-0 items-center gap-1.5 self-start rounded-lg border border-amber-400 bg-amber-100 px-2.5 py-1 text-[11px] font-semibold text-amber-900 shadow-sm transition hover:bg-amber-200 disabled:cursor-not-allowed disabled:opacity-60">
                            <x-heroicon-o-pause class="h-3.5 w-3.5" wire:loading.remove wire:target="confirmSuspendSite" aria-hidden="true" />
                            <x-spinner wire:loading wire:target="confirmSuspendSite" variant="amber" size="sm" />
                            <span wire:loading.remove wire:target="confirmSuspendSite">{{ __('Suspend') }}</span>
                            <span wire:loading wire:target="confirmSuspendSite">{{ __('Suspending…') }}</span>
                        </button>
                    @endif
                @endcan
            </div>
        </section>
    @else
        <div class="border-b border-brand-ink/10 px-3 py-2.5 sm:px-4">
            <p class="text-xs leading-relaxed text-brand-moss">{{ __('Suspending HTTP traffic is only available for VM sites with managed web server config (not serverless, Docker, or Kubernetes).') }}</p>
        </div>
    @endif

    {{-- Clone site --}}
    @can('clone', $site)
        <section class="border-b border-brand-ink/10">
            <div class="flex flex-col gap-2.5 px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between sm:gap-4 sm:px-4">
                <div class="min-w-0">
                    <div class="flex items-center gap-1.5">
                        <x-heroicon-o-document-duplicate class="h-4 w-4 shrink-0 text-sky-700" aria-hidden="true" />
                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Clone site') }}</h3>
                    </div>
                    <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ __('Copy to another server with a new domain. Databases, certs, env files, and custom Nginx snippets are not copied.') }}</p>
                </div>
                <a href="{{ route('sites.clone', [$server, $site]) }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 self-start rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 sm:self-center">
                    <x-heroicon-o-document-duplicate class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('Clone') }}
                </a>
            </div>
        </section>

        @feature('workspace.site_promote')
        @if ($server->isVmHost())
            <section class="border-b border-brand-ink/10">
                <div class="flex flex-col gap-2.5 px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between sm:gap-4 sm:px-4">
                    <div class="min-w-0">
                        <div class="flex items-center gap-1.5">
                            <x-heroicon-o-arrow-right-circle class="h-4 w-4 shrink-0 text-brand-forest" aria-hidden="true" />
                            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Promote to another server') }}</h3>
                        </div>
                        <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ __('Copy to a standby on a preview hostname, smoke-test, then cut over production DNS.') }}</p>
                    </div>
                    <a href="{{ route('sites.promote', [$server, $site]) }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 self-start rounded-lg border border-brand-sage/30 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-forest shadow-sm transition hover:bg-brand-sage/10 sm:self-center">
                        <x-heroicon-o-arrow-right-circle class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ __('Promote') }}
                    </a>
                </div>
            </section>
        @endif
        @endfeature
    @endcan

    {{-- Delete site --}}
    @can('delete', $site)
        <section class="border-b border-rose-200 last:border-b-0">
            <div @class([
                'flex flex-col gap-2.5 bg-rose-50/60 px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between sm:gap-4 sm:px-4',
                'border-b border-rose-200' => $site->scheduled_deletion_at,
            ])>
                <div class="min-w-0">
                    <div class="flex items-center gap-1.5">
                        <x-heroicon-o-trash class="h-4 w-4 shrink-0 text-rose-700" aria-hidden="true" />
                        <h3 class="text-sm font-semibold text-rose-900">{{ __('Delete site') }}</h3>
                    </div>
                    <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ __('Removes the site from Dply and queues cleanup of the vhost, optional releases/repo/cert, supervisor rows, deploy key, and crontab. Schedule later for a grace window.') }}</p>
                </div>
                @unless ($site->scheduled_deletion_at)
                    <button type="button" wire:click="openRemoveSiteModal" class="inline-flex shrink-0 items-center gap-1.5 self-start rounded-lg border border-rose-300 bg-white px-2.5 py-1 text-[11px] font-semibold text-rose-800 shadow-sm transition hover:bg-rose-100 sm:self-center">
                        <x-heroicon-o-trash class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ __('Delete') }}
                    </button>
                @endunless
            </div>

            @if ($site->scheduled_deletion_at)
                <div class="px-3 py-2 sm:px-4">
                    <div class="flex flex-wrap items-center gap-2 rounded-lg border border-amber-300 bg-amber-50 px-2.5 py-1.5 text-[11px] text-amber-900">
                        <x-heroicon-o-clock class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        <span class="min-w-0 flex-1">
                            {{ __('Scheduled for removal at :time.', ['time' => $site->scheduled_deletion_at->copy()->timezone(config('app.timezone'))->toDayDateTimeString()]) }}
                        </span>
                        <button type="button" wire:click="cancelScheduledSiteRemoval" wire:loading.attr="disabled" wire:target="cancelScheduledSiteRemoval" class="inline-flex items-center gap-1 rounded-md border border-amber-300 bg-white px-2 py-0.5 font-semibold hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-60">
                            <x-heroicon-o-x-mark class="h-3.5 w-3.5" wire:loading.remove wire:target="cancelScheduledSiteRemoval" aria-hidden="true" />
                            <x-spinner wire:loading wire:target="cancelScheduledSiteRemoval" variant="amber" size="sm" />
                            <span wire:loading.remove wire:target="cancelScheduledSiteRemoval">{{ __('Cancel') }}</span>
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

    <div class="bg-brand-sand/25 px-3 py-2 sm:px-4">
        <x-cli-snippet :commands="[
            ['label' => __('Tear down systemd units'), 'command' => 'dply sites:systemd:teardown '.$site->slug],
            ['label' => __('Re-sync systemd units'), 'command' => 'dply sites:systemd:redeploy '.$site->slug],
        ]" />
    </div>
</div>

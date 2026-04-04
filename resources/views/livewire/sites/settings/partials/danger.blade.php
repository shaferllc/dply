<section class="rounded-2xl border border-red-200 bg-white p-6 shadow-sm sm:p-8 space-y-6">
    <div>
        <h2 class="text-lg font-semibold text-red-900">{{ __('Danger zone') }}</h2>
        <p class="mt-1 text-sm text-red-800">{{ __('High-impact actions: suspending public traffic or deleting the site from Dply.') }}</p>
    </div>

    @if ($this->shouldAutoReapplyManagedWebserverConfig())
        <div class="rounded-xl border border-amber-200 bg-amber-50/80 p-4 space-y-3">
            <div>
                <h3 class="text-sm font-semibold text-amber-950">{{ __('Suspend public site') }}</h3>
                <p class="mt-1 text-sm text-amber-900/90">{{ __('Visitors will see a suspended page instead of your app until you resume. Deploy hooks and Dply settings still work.') }}</p>
            </div>
            @if ($site->isSuspended())
                <p class="text-sm font-medium text-amber-950">{{ __('This site is currently suspended.') }}</p>
                @can('update', $site)
                    <button type="button" wire:click="resumeSite" wire:loading.attr="disabled" class="rounded-xl border border-amber-300 bg-white px-4 py-2.5 text-sm font-medium text-amber-950 hover:bg-amber-100">
                        {{ __('Resume site') }}
                    </button>
                @endcan
            @else
                <div class="space-y-2">
                    <label for="settings_suspended_message" class="block text-xs font-medium text-amber-950">{{ __('Public message (optional)') }}</label>
                    <textarea id="settings_suspended_message" wire:model="settings_suspended_message" rows="2" maxlength="500" class="mt-0.5 block w-full rounded-lg border border-amber-200 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm placeholder:text-slate-400" placeholder="{{ __('Shown on the suspended page — e.g. billing or contact info.') }}"></textarea>
                    @error('settings_suspended_message')
                        <p class="text-sm text-red-700">{{ $message }}</p>
                    @enderror
                </div>
                @can('update', $site)
                    <button type="button" wire:click="confirmSuspendSite" wire:loading.attr="disabled" class="rounded-xl border border-amber-400 bg-amber-100 px-4 py-2.5 text-sm font-medium text-amber-950 hover:bg-amber-200">
                        {{ __('Suspend site') }}
                    </button>
                @endcan
            @endif
        </div>
    @else
        <p class="text-sm text-slate-600">{{ __('Suspending HTTP traffic from the edge is only available for VM sites with managed web server configuration (not serverless, Docker, or Kubernetes runtimes).') }}</p>
    @endif

    @can('clone', $site)
        <div class="rounded-xl border border-brand-ink/10 bg-slate-50/80 p-4 space-y-2">
            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Clone site') }}</h3>
            <p class="text-sm text-slate-600">{{ __('Create a copy on another server in your organization with a new domain. Databases, SSL certificates, environment files, and custom Nginx extra snippets are not copied.') }}</p>
            <a href="{{ route('sites.clone', [$server, $site]) }}" wire:navigate class="inline-flex rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">
                {{ __('Clone site') }}
            </a>
        </div>
    @endcan

    @can('delete', $site)
        <button type="button" wire:click="openConfirmActionModal('deleteSite', [], @js(__('Delete site')), @js(__('Delete this site from Dply? A background job removes Nginx vhost, optional releases/repo/cert, supervisor rows tied to this site, deploy SSH key, and re-syncs server crontab.')), @js(__('Delete site')), true)" class="rounded-xl border border-red-300 bg-red-50 px-4 py-2.5 text-sm font-medium text-red-800 hover:bg-red-100">
            {{ __('Delete site') }}
        </button>
    @endcan
</section>

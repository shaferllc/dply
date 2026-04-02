<section class="rounded-2xl border border-red-200 bg-white p-6 shadow-sm sm:p-8 space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-red-900">{{ __('Danger zone') }}</h2>
        <p class="mt-1 text-sm text-red-800">{{ __('Delete the site from Dply. This stays on its own tab so destructive actions are separated from normal site configuration.') }}</p>
    </div>

    @can('delete', $site)
        <button type="button" wire:click="openConfirmActionModal('deleteSite', [], @js(__('Delete site')), @js(__('Delete this site from Dply? A background job removes Nginx vhost, optional releases/repo/cert, supervisor rows tied to this site, deploy SSH key, and re-syncs server crontab.')), @js(__('Delete site')), true)" class="rounded-xl border border-red-300 bg-red-50 px-4 py-2.5 text-sm font-medium text-red-800 hover:bg-red-100">
            {{ __('Delete site') }}
        </button>
    @endcan
</section>

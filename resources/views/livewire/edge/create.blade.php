<div class="mx-auto max-w-3xl px-6 py-10">
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Infrastructure'), 'href' => route('infrastructure.index'), 'icon' => 'rectangle-group'],
        ['label' => __('Edge'), 'href' => route('edge.index'), 'icon' => 'globe-alt'],
        ['label' => __('Create'), 'icon' => 'plus'],
    ]" />

    <header class="mb-8">
        <h1 class="text-3xl font-semibold text-slate-900">{{ __('Deploy an edge app') }}</h1>
        <p class="mt-2 text-sm text-slate-600">{{ __('Connect a Git repository and dply builds static or SSG output, publishes to global edge delivery, and optionally redeploys on push.') }}</p>
    </header>

    @if ($fakeEdgeActive)
        <div data-testid="fake-edge-active-notice" class="mb-6 rounded-2xl border border-sky-200 bg-sky-50/60 p-4 text-sm text-sky-900">
            <p class="font-semibold">{{ __('Fake-edge mode is on') }}</p>
            <p class="mt-1">{{ __('Builds land on the in-memory fake backend with synthetic hostnames. No Cloudflare credentials required in local/testing.') }}</p>
        </div>
    @endif

    <form wire:submit="deploy" class="space-y-6">
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
            <div>
                <x-input-label for="name" :value="__('App name')" />
                <x-text-input id="name" wire:model="name" type="text" class="mt-1 block w-full" required placeholder="marketing-site" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="repo" :value="__('Git repository')" />
                    <x-text-input id="repo" wire:model="repo" type="text" class="mt-1 block w-full" required placeholder="owner/repo" />
                    <p class="mt-1 text-xs text-slate-500">{{ __('owner/repo or a full GitHub URL') }}</p>
                    <x-input-error :messages="$errors->get('repo')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="branch" :value="__('Branch')" />
                    <x-text-input id="branch" wire:model="branch" type="text" class="mt-1 block w-full" required />
                    <x-input-error :messages="$errors->get('branch')" class="mt-2" />
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-slate-600">{{ __('Detect framework and suggested build settings from the repository.') }}</p>
                    <button type="button" wire:click="detectFromRepository" wire:loading.attr="disabled" wire:target="detectFromRepository" class="inline-flex shrink-0 items-center rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800 disabled:opacity-50">
                        <span wire:loading.remove wire:target="detectFromRepository">{{ __('Detect runtime') }}</span>
                        <span wire:loading wire:target="detectFromRepository">{{ __('Detecting…') }}</span>
                    </button>
                </div>
                @include('livewire.partials._runtime-detection-panel')
            </div>

            <div>
                <x-input-label for="build_command" :value="__('Build command override')" />
                <x-text-input id="build_command" wire:model="build_command" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="npm ci && npm run build" />
                <p class="mt-1 text-xs text-slate-500">{{ __('Leave blank to use the detected command or the default npm build.') }}</p>
                <x-input-error :messages="$errors->get('build_command')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="output_dir" :value="__('Output directory')" />
                <x-text-input id="output_dir" wire:model="output_dir" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="dist" />
                <p class="mt-1 text-xs text-slate-500">{{ __('Folder containing the static assets after the build (e.g. dist, out, .output/public).') }}</p>
                <x-input-error :messages="$errors->get('output_dir')" class="mt-2" />
            </div>

            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model="spa_fallback" class="mt-1 rounded border-slate-300 text-sky-700 shadow-sm" />
                <span>
                    <span class="text-sm font-medium text-slate-900">{{ __('SPA fallback') }}</span>
                    <span class="mt-0.5 block text-xs text-slate-500">{{ __('Serve index.html for unknown paths — typical for client-side routed SPAs.') }}</span>
                </span>
            </label>

            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model="deploy_on_push" class="mt-1 rounded border-slate-300 text-sky-700 shadow-sm" />
                <span>
                    <span class="text-sm font-medium text-slate-900">{{ __('Deploy on push') }}</span>
                    <span class="mt-0.5 block text-xs text-slate-500">{{ __('When a GitHub webhook is configured, pushes to the production branch trigger a rebuild.') }}</span>
                </span>
            </label>
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('edge.index') }}" wire:navigate class="text-sm font-medium text-slate-600 hover:text-slate-900">{{ __('Cancel') }}</a>
            <button type="submit" wire:loading.attr="disabled" class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:opacity-50">
                <span wire:loading.remove wire:target="deploy">{{ __('Deploy edge app') }}</span>
                <span wire:loading wire:target="deploy">{{ __('Queueing…') }}</span>
            </button>
        </div>
    </form>
</div>

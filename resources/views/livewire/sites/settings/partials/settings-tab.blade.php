{{-- Site Settings tab — editable fields for things that don't have their own
     dedicated section. Primary hostname is intentionally NOT here — it's edited
     from Routing > Domains, which triggers the rename cascade modal. --}}

{{-- Site identity (display name + slug). Mirrors dply:site:rename semantics: row
     update only, on-disk path under /home/dply/<domain> stays put. --}}
<section class="dply-card overflow-hidden">
    <form wire:submit="saveSiteIdentity">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-identification class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Identity') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Site identity') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Display name and URL slug for the site in dashboards and CLI. The on-disk deploy path under /home/dply/<domain> is not renamed by this — keep deploys in mind before changing the slug.') }}
                </p>
            </div>
        </div>

        <div class="space-y-5 px-6 py-6 sm:px-7">
                <div>
                    <x-input-label for="settings_site_name" :value="__('Display name')" />
                    <x-text-input id="settings_site_name" wire:model="settings_site_name" class="mt-2 block w-full text-sm" />
                    <x-input-error :messages="$errors->get('settings_site_name')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="settings_site_slug" :value="__('Slug')" />
                    <x-text-input id="settings_site_slug" wire:model="settings_site_slug" class="mt-2 block w-full font-mono text-sm" />
                    <x-input-error :messages="$errors->get('settings_site_slug')" class="mt-2" />
                    <p class="mt-2 text-xs text-brand-moss">
                        {{ __('Lowercase letters, digits, and hyphens. Used in URLs and the deploy-path stub.') }}
                    </p>
                </div>
        </div>

        <div class="flex justify-end border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4 sm:px-7">
            <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
        </div>
    </form>
</section>

{{-- Web directory — document_root. VM-only: container/serverless apps don't
     have an on-disk document root (no host webserver, no nginx vhost). Editing
     the primary domain itself happens in Routing because the cascade (cert /
     backend / dns_zone) belongs to the domain change, not to the path. --}}
@if (! $isContainerWorkspace)
<section class="dply-card mt-6 overflow-hidden">
    <form wire:submit="saveWebDirectory">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-folder class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Path') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $documentRootLabel }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Path nginx serves from for this site. Webserver config re-applies on save.') }}
                </p>
            </div>
        </div>

        <div class="space-y-5 px-6 py-6 sm:px-7">
                <div>
                    <x-input-label for="settings_document_root" :value="$documentRootLabel" />
                    <x-text-input id="settings_document_root" wire:model="settings_document_root" class="mt-2 block w-full font-mono text-sm" :placeholder="$documentRootPlaceholder" />
                    <x-input-error :messages="$errors->get('settings_document_root')" class="mt-2" />
                </div>
        </div>

        <div class="flex justify-end border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4 sm:px-7">
            <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
        </div>
    </form>
</section>
@endif

{{-- Serving mode — worker lockdown. When on, Caddy serves a static "this runs
     workers" page for every request and the deployed code is never browsable.
     VM-only; worker hosts default this on. --}}
@if ($this->canConfigureWorkerMode)
<section class="dply-card mt-6 overflow-hidden">
    <form wire:submit="saveWorkerMode">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Serving mode') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Worker mode') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('A worker site only runs background queue workers and scheduled jobs — it has no website. When on, the public URL is locked down to a static “this runs workers” page and the deployed code is never browsable. The web server re-applies on save.') }}
                </p>
            </div>
        </div>

        <div class="px-6 py-6 sm:px-7">
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model="worker_mode" class="mt-0.5 h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage/30" />
                <span class="min-w-0">
                    <span class="block text-sm font-medium text-brand-ink">{{ __('Run this site as a worker (no web interface)') }}</span>
                    <span class="mt-1 block text-xs text-brand-moss">
                        @if ($server->isWorkerHost())
                            {{ __('This server is a worker host, so worker mode is on by default. Turning it off lets this site serve its deployed app over HTTP.') }}
                        @else
                            {{ __('Turn this on for a site that only processes queues and schedules. Leave it off for a normal web app.') }}
                        @endif
                    </span>
                </span>
            </label>
        </div>

        @if ($worker_mode)
            <div class="border-t border-brand-ink/10 px-6 py-6 sm:px-7">
                <x-input-label for="worker_page_html" :value="__('Custom worker page (optional)')" />
                <textarea id="worker_page_html" wire:model="worker_page_html" rows="10"
                    class="mt-2 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    placeholder="<!doctype html>&#10;<html>&#10;  <body>&#10;    <h1>@{{site_name}} runs workers</h1>&#10;  </body>&#10;</html>"></textarea>
                <x-input-error :messages="$errors->get('worker_page_html')" class="mt-2" />
                <p class="mt-2 text-xs text-brand-moss">
                    {{ __('Leave empty to serve the built-in dply page. Your HTML is served as-is for every request. These tokens are replaced with this site’s values:') }}
                    <code class="rounded bg-brand-sand/40 px-1">@{{site_name}}</code>,
                    <code class="rounded bg-brand-sand/40 px-1">@{{server_name}}</code>,
                    <code class="rounded bg-brand-sand/40 px-1">@{{runtime}}</code>,
                    <code class="rounded bg-brand-sand/40 px-1">@{{hostname}}</code>.
                </p>
            </div>
        @endif

        <div class="flex justify-end border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4 sm:px-7">
            <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
        </div>
    </form>
</section>
@endif

{{-- Project / workspace assignment — moved from General. --}}
<section class="dply-card mt-6 overflow-hidden">
    <form wire:submit="saveProjectSettings">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-folder-open class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Project') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $projectSettingsTitle }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ $projectSettingsDescription }}
                </p>
            </div>
        </div>

        <div class="space-y-5 px-6 py-6 sm:px-7">
                <div>
                    <x-input-label for="project_workspace_id" value="Project" />
                    <select id="project_workspace_id" wire:model="project_workspace_id" class="dply-input">
                        <option value="">{{ __('No project') }}</option>
                        @foreach ($availableWorkspaces as $workspace)
                            <option value="{{ $workspace->id }}">{{ $workspace->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('project_workspace_id')" class="mt-2" />
                    <p class="mt-2 text-sm text-brand-moss">
                        {{ __('Project membership can be managed here or from the project resources page.') }}
                    </p>
                </div>

                @if ($site->workspace)
                    @feature('surface.projects')
                        <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                            <p class="text-sm font-semibold text-brand-ink">{{ __('Current project') }}</p>
                            <p class="mt-1 text-sm text-brand-moss">
                                {{ __('This site currently rolls up into :project.', ['project' => $site->workspace->name]) }}
                            </p>
                            <div class="mt-3 flex flex-wrap gap-3 text-sm">
                                <a href="{{ route('projects.resources', $site->workspace) }}" wire:navigate class="font-medium text-brand-forest hover:text-brand-sage hover:underline">{{ __('Open project resources') }}</a>
                                <a href="{{ route('projects.operations', $site->workspace) }}" wire:navigate class="font-medium text-brand-forest hover:text-brand-sage hover:underline">{{ __('Open project operations') }}</a>
                                <a href="{{ route('projects.delivery', $site->workspace) }}" wire:navigate class="font-medium text-brand-forest hover:text-brand-sage hover:underline">{{ __('Open project delivery') }}</a>
                            </div>
                        </div>
                    @endfeature
                @endif
        </div>

        <div class="flex justify-end border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4 sm:px-7">
            <x-primary-button type="submit">{{ __('Save project settings') }}</x-primary-button>
        </div>
    </form>
</section>

{{-- Site notes — moved from General. --}}
<section class="dply-card mt-6 overflow-hidden">
    <form wire:submit="saveSiteNotes">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-pencil-square class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Notes') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Site notes') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Keep operational notes here for details you want to save or hand off later. Avoid putting secrets or credentials in this field.') }}
                </p>
            </div>
        </div>

        <div class="space-y-4 px-6 py-6 sm:px-7">
                <div>
                    <x-input-label for="site_notes" value="Notes" />
                    <textarea id="site_notes" wire:model="site_notes" rows="5" class="mt-2 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"></textarea>
                    <x-input-error :messages="$errors->get('site_notes')" class="mt-2" />
                </div>
        </div>

        <div class="flex justify-end border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4 sm:px-7">
            <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
        </div>
    </form>
</section>

{{-- CLI snippet — every settings section ships one so operators always know the
     equivalent dply CLI command. This section's edits are name/slug only, both
     of which today flow through the rename cascade modal rather than a direct
     CLI verb, so the stub variant explains the gap until that lands. --}}
<x-cli-snippet :commands="[
    ['label' => __('Show site'), 'command' => 'dply sites:show '.$site->slug],
    ['label' => __('Rename site'), 'command' => 'dply sites:rename '.$site->slug.' <new-slug>'],
]" />

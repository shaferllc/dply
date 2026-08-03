{{-- Site Settings tab — editable fields for things that don't have their own
     dedicated section. Primary hostname is intentionally NOT here — it's edited
     from Routing > Domains, which triggers the rename cascade modal. --}}

@php
    // Shared density classes — same compact header / body / footer rhythm the
    // General and Runtime tabs use, so the workspace panels read as one system.
    $panelHead = 'flex flex-wrap items-start justify-between gap-x-4 gap-y-2 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-3.5 sm:px-6';
    $panelBody = 'px-5 py-4 sm:px-6';
    $panelFoot = 'flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-2.5 sm:px-6';
    $panelIcon = 'h-4 w-4 shrink-0 text-brand-sage';
    $panelTitle = 'text-sm font-semibold text-brand-ink';
    $panelNote = 'mt-1 max-w-3xl text-xs leading-relaxed text-brand-moss';
    $fieldHelp = 'mt-1 text-xs text-brand-moss';
    $btnBase = 'inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:opacity-60';
@endphp

{{-- Site identity (display name + slug). Mirrors dply sites:rename semantics: row
     update only, on-disk path under /home/dply/<domain> stays put. --}}
<div class="border-b border-brand-ink/10">
    <form wire:submit="saveSiteIdentity">
        <div class="{{ $panelHead }}">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-identification class="{{ $panelIcon }}" aria-hidden="true" />
                    <h2 class="{{ $panelTitle }}">{{ __('Site identity') }}</h2>
                </div>
                <p class="{{ $panelNote }}">
                    {{ __('Display name and URL slug for the site in dashboards and CLI. The on-disk deploy path under /home/dply/<domain> is not renamed by this — keep deploys in mind before changing the slug.') }}
                </p>
            </div>
        </div>

        <div class="{{ $panelBody }}">
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <x-input-label for="settings_site_name" :value="__('Display name')" class="!text-xs" />
                    <x-text-input id="settings_site_name" wire:model="settings_site_name" class="mt-1 block w-full text-sm" />
                    <x-input-error :messages="$errors->get('settings_site_name')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="settings_site_slug" :value="__('Slug')" class="!text-xs" />
                    <x-text-input id="settings_site_slug" wire:model="settings_site_slug" class="mt-1 block w-full font-mono text-sm" />
                    <x-input-error :messages="$errors->get('settings_site_slug')" class="mt-1" />
                    <p class="{{ $fieldHelp }}">
                        {{ __('Lowercase letters, digits, and hyphens. Used in URLs and the deploy-path stub.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="{{ $panelFoot }}">
            <x-primary-button size="sm" type="submit" wire:loading.attr="disabled" wire:target="saveSiteIdentity">
                <span wire:loading.remove wire:target="saveSiteIdentity">{{ __('Save') }}</span>
                <span wire:loading wire:target="saveSiteIdentity">{{ __('Saving…') }}</span>
            </x-primary-button>
        </div>
    </form>
</div>

{{-- Web directory — document_root. VM-only: container/serverless apps don't
     have an on-disk document root (no host webserver, no nginx vhost). Editing
     the primary domain itself happens in Routing because the cascade (cert /
     backend / dns_zone) belongs to the domain change, not to the path.
     The field label is sr-only: the panel title already names it. --}}
@if (! $isContainerWorkspace)
<div class="border-b border-brand-ink/10">
    <form wire:submit="saveWebDirectory">
        <div class="{{ $panelHead }}">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-folder class="{{ $panelIcon }}" aria-hidden="true" />
                    <h2 class="{{ $panelTitle }}">{{ $documentRootLabel }}</h2>
                </div>
                <p class="{{ $panelNote }}">
                    {{ __('Path nginx serves from for this site. Webserver config re-applies on save.') }}
                </p>
            </div>
        </div>

        <div class="{{ $panelBody }}">
            <x-input-label for="settings_document_root" :value="$documentRootLabel" class="sr-only" />
            <x-text-input id="settings_document_root" wire:model="settings_document_root" class="block w-full font-mono text-sm" :placeholder="$documentRootPlaceholder" />
            <x-input-error :messages="$errors->get('settings_document_root')" class="mt-1" />
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-2.5 sm:px-6">
            <button type="button" wire:click="rebuildWebserverConfig" wire:loading.attr="disabled" wire:target="rebuildWebserverConfig"
                class="{{ $btnBase }}"
                title="{{ __('Re-apply this site’s nginx vhost — fixes a 502 caused by a missing/stale config without changing anything.') }}">
                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" aria-hidden="true" />
                <span wire:loading.remove wire:target="rebuildWebserverConfig">{{ __('Rebuild webserver config') }}</span>
                <span wire:loading wire:target="rebuildWebserverConfig">{{ __('Rebuilding…') }}</span>
            </button>
            <x-primary-button size="sm" type="submit" wire:loading.attr="disabled" wire:target="saveWebDirectory">
                <span wire:loading.remove wire:target="saveWebDirectory">{{ __('Save') }}</span>
                <span wire:loading wire:target="saveWebDirectory">{{ __('Saving…') }}</span>
            </x-primary-button>
        </div>
    </form>
</div>
@endif

{{-- Serving mode — worker lockdown. When on, Caddy serves a static "this runs
     workers" page for every request and the deployed code is never browsable.
     VM-only; worker hosts default this on. The "what a worker site is" sentence
     lives on the checkbox helper below, not twice. --}}
@if ($this->canConfigureWorkerMode)
<div class="border-b border-brand-ink/10">
    <form wire:submit="saveWorkerMode">
        <div class="{{ $panelHead }}">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-server-stack class="{{ $panelIcon }}" aria-hidden="true" />
                    <h2 class="{{ $panelTitle }}">{{ __('Worker mode') }}</h2>
                </div>
                <p class="{{ $panelNote }}">
                    {{ __('When on, the public URL is locked down to a static “this runs workers” page and the deployed code is never browsable. The web server re-applies on save.') }}
                </p>
            </div>
        </div>

        <div class="{{ $panelBody }}">
            <label class="flex items-start gap-2.5">
                <input type="checkbox" wire:model="worker_mode" class="mt-0.5 h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage/30" />
                <span class="min-w-0">
                    <span class="block text-xs font-semibold text-brand-ink">{{ __('Run this site as a worker (no web interface)') }}</span>
                    <span class="mt-0.5 block text-xs text-brand-moss">
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
            <div class="border-t border-brand-ink/10 {{ $panelBody }}">
                <x-input-label for="worker_page_html" :value="__('Custom worker page (optional)')" class="!text-xs" />
                <textarea id="worker_page_html" wire:model="worker_page_html" rows="8"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    placeholder="<!doctype html>&#10;<html>&#10;  <body>&#10;    <h1>@{{site_name}} runs workers</h1>&#10;  </body>&#10;</html>"></textarea>
                <x-input-error :messages="$errors->get('worker_page_html')" class="mt-1" />
                <p class="{{ $fieldHelp }}">
                    {{ __('Leave empty to serve the built-in dply page. Your HTML is served as-is for every request. These tokens are replaced with this site’s values:') }}
                    <code class="rounded bg-brand-sand/40 px-1">@{{site_name}}</code>,
                    <code class="rounded bg-brand-sand/40 px-1">@{{server_name}}</code>,
                    <code class="rounded bg-brand-sand/40 px-1">@{{runtime}}</code>,
                    <code class="rounded bg-brand-sand/40 px-1">@{{hostname}}</code>.
                </p>
            </div>
        @endif

        <div class="{{ $panelFoot }}">
            <x-primary-button size="sm" type="submit" wire:loading.attr="disabled" wire:target="saveWorkerMode">
                <span wire:loading.remove wire:target="saveWorkerMode">{{ __('Save') }}</span>
                <span wire:loading wire:target="saveWorkerMode">{{ __('Saving…') }}</span>
            </x-primary-button>
        </div>
    </form>
</div>
@endif

{{-- Project / workspace assignment — moved from General. Field label is
     sr-only: the panel title already names it. --}}
<div class="border-b border-brand-ink/10">
    <form wire:submit="saveProjectSettings">
        <div class="{{ $panelHead }}">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-folder-open class="{{ $panelIcon }}" aria-hidden="true" />
                    <h2 class="{{ $panelTitle }}">{{ $projectSettingsTitle }}</h2>
                </div>
                <p class="{{ $panelNote }}">{{ $projectSettingsDescription }}</p>
            </div>
        </div>

        <div class="{{ $panelBody }} space-y-2">
            <div>
                <x-input-label for="project_workspace_id" value="Project" class="sr-only" />
                <select id="project_workspace_id" wire:model="project_workspace_id" class="dply-input">
                    <option value="">{{ __('No project') }}</option>
                    @foreach ($availableWorkspaces as $workspace)
                        <option value="{{ $workspace->id }}">{{ $workspace->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('project_workspace_id')" class="mt-1" />
                <p class="{{ $fieldHelp }}">
                    {{ __('Project membership can be managed here or from the project resources page.') }}
                </p>
            </div>

            @if ($site->workspace)
                @feature('surface.projects')
                    <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-3 py-2">
                        <p class="text-xs text-brand-moss">
                            {{ __('This site currently rolls up into :project.', ['project' => $site->workspace->name]) }}
                        </p>
                        <div class="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 text-xs font-semibold">
                            <a href="{{ route('projects.resources', $site->workspace) }}" wire:navigate class="text-brand-forest hover:text-brand-sage hover:underline">{{ __('Open project resources') }}</a>
                            <a href="{{ route('projects.operations', $site->workspace) }}" wire:navigate class="text-brand-forest hover:text-brand-sage hover:underline">{{ __('Open project operations') }}</a>
                            <a href="{{ route('projects.delivery', $site->workspace) }}" wire:navigate class="text-brand-forest hover:text-brand-sage hover:underline">{{ __('Open project delivery') }}</a>
                        </div>
                    </div>
                @endfeature
            @endif
        </div>

        <div class="{{ $panelFoot }}">
            <x-primary-button size="sm" type="submit" wire:loading.attr="disabled" wire:target="saveProjectSettings">
                <span wire:loading.remove wire:target="saveProjectSettings">{{ __('Save project settings') }}</span>
                <span wire:loading wire:target="saveProjectSettings">{{ __('Saving…') }}</span>
            </x-primary-button>
        </div>
    </form>
</div>

{{-- Site notes — moved from General. Field label is sr-only: the panel title
     already names it. --}}
<div class="border-b border-brand-ink/10">
    <form wire:submit="saveSiteNotes">
        <div class="{{ $panelHead }}">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-pencil-square class="{{ $panelIcon }}" aria-hidden="true" />
                    <h2 class="{{ $panelTitle }}">{{ __('Site notes') }}</h2>
                </div>
                <p class="{{ $panelNote }}">
                    {{ __('Keep operational notes here for details you want to save or hand off later. Avoid putting secrets or credentials in this field.') }}
                </p>
            </div>
        </div>

        <div class="{{ $panelBody }}">
            <x-input-label for="site_notes" value="Notes" class="sr-only" />
            <textarea id="site_notes" wire:model="site_notes" rows="4" class="block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"></textarea>
            <x-input-error :messages="$errors->get('site_notes')" class="mt-1" />
        </div>

        <div class="{{ $panelFoot }}">
            <x-primary-button size="sm" type="submit" wire:loading.attr="disabled" wire:target="saveSiteNotes">
                <span wire:loading.remove wire:target="saveSiteNotes">{{ __('Save') }}</span>
                <span wire:loading wire:target="saveSiteNotes">{{ __('Saving…') }}</span>
            </x-primary-button>
        </div>
    </form>
</div>

{{-- Error pages — VM webserver sites only. By default dply does NOT intercept:
     the app renders its own error pages (its 500/503, the framework debug page
     when APP_DEBUG is on, or the webserver's own 502/504 when the upstream is
     down). Switch on the branded page to mask 5xx with dply's "temporarily
     unavailable" splash, which also carries a reference id for the Errors tab.
     Single control, so state pill + toggle ride in the header — no body row. --}}
@if (! $isContainerWorkspace)
    @php $rawServerErrors = $this->serverErrorsExposed(); @endphp
    <div class="border-b border-brand-ink/10">
        <div class="{{ $panelHead }}">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <x-heroicon-o-exclamation-triangle class="{{ $panelIcon }}" aria-hidden="true" />
                    <h2 class="{{ $panelTitle }}">{{ __('When this site hits a 5xx') }}</h2>
                    <span @class([
                        'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-semibold',
                        'bg-brand-sage/15 text-brand-moss ring-1 ring-inset ring-brand-sage/30' => $rawServerErrors,
                        'bg-brand-sand/60 text-brand-moss ring-1 ring-inset ring-brand-ink/10' => ! $rawServerErrors,
                    ])>
                        @if ($rawServerErrors)
                            <x-heroicon-o-code-bracket class="h-3.5 w-3.5" aria-hidden="true" /> {{ __('App handles its own errors') }}
                        @else
                            <x-heroicon-o-shield-check class="h-3.5 w-3.5" aria-hidden="true" /> {{ __('Branded dply error page') }}
                        @endif
                    </span>
                </div>
                <p class="{{ $panelNote }}">
                    {{ __('By default dply lets your app handle its own errors — visitors see the page your app renders for a 500/503. Turn on dply\'s branded page to show a "temporarily unavailable" splash instead (with a reference id you can paste into the Errors tab to find the matching error).') }}
                </p>
            </div>

            @if ($rawServerErrors)
                <button
                    type="button"
                    wire:click="hideServerErrors"
                    wire:loading.attr="disabled"
                    wire:target="hideServerErrors"
                    class="shrink-0 {{ $btnBase }}"
                >
                    <x-heroicon-o-shield-check class="h-3.5 w-3.5" aria-hidden="true" />
                    <span wire:loading.remove wire:target="hideServerErrors">{{ __('Use branded dply page') }}</span>
                    <span wire:loading wire:target="hideServerErrors">{{ __('Applying…') }}</span>
                </button>
            @else
                <button
                    type="button"
                    wire:click="exposeServerErrors"
                    wire:loading.attr="disabled"
                    wire:target="exposeServerErrors"
                    class="shrink-0 {{ $btnBase }}"
                >
                    <x-heroicon-o-code-bracket class="h-3.5 w-3.5" aria-hidden="true" />
                    <span wire:loading.remove wire:target="exposeServerErrors">{{ __('Let the app handle errors') }}</span>
                    <span wire:loading wire:target="exposeServerErrors">{{ __('Applying…') }}</span>
                </button>
            @endif
        </div>
    </div>
@endif

{{-- CLI snippet — every settings section ships one so operators always know the
     equivalent dply CLI command. This section's edits are name/slug only, both
     of which today flow through the rename cascade modal rather than a direct
     CLI verb, so the stub variant explains the gap until that lands. --}}
<div class="px-5 py-3 sm:px-6">
<x-cli-snippet :commands="[
    ['label' => __('Show site'), 'command' => 'dply sites:show '.$site->slug],
    ['label' => __('Rename site'), 'command' => 'dply sites:rename '.$site->slug.' <new-slug>'],
]" />
</div>

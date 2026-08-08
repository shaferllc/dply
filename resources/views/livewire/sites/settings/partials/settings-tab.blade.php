{{-- Site Settings tab — editable fields for things that don't have their own
     dedicated section. Primary hostname is intentionally NOT here — it's edited
     from Routing > Domains, which triggers the rename cascade modal.

     Dense hairline strips: panel-head (Save in actions) + compact field body.
     Per-section Livewire saves stay; no shared unsaved bar. --}}

@php
    $panelBody = 'px-5 py-3 sm:px-6';
    $fieldHelp = 'mt-1 text-[11px] text-brand-moss';
    $btnOutline = 'dply-btn dply-btn-xs dply-btn-outline';
@endphp

{{-- Site identity (display name + slug). Mirrors dply sites:rename semantics: row
     update only, on-disk path under /home/dply/<domain> stays put. --}}
<form wire:submit="saveSiteIdentity" class="border-b border-brand-ink/10">
    <x-workspace-panel-head
        dense
        class="border-b border-brand-ink/10"
        icon="heroicon-o-identification"
        :title="__('Site identity')"
        :note="__('Display name and URL slug. On-disk path under /home/dply/<domain> is not renamed.')"
    >
        <x-slot:actions>
            <x-primary-button size="sm" type="submit" wire:loading.attr="disabled" wire:target="saveSiteIdentity">
                <span wire:loading.remove wire:target="saveSiteIdentity">{{ __('Save') }}</span>
                <span wire:loading wire:target="saveSiteIdentity">{{ __('Saving…') }}</span>
            </x-primary-button>
        </x-slot:actions>
    </x-workspace-panel-head>

    <div class="{{ $panelBody }}">
        <div class="grid gap-2.5 sm:grid-cols-2">
            <div>
                <x-input-label for="settings_site_name" :value="__('Display name')" class="!text-xs" />
                <x-text-input id="settings_site_name" wire:model="settings_site_name" class="mt-1 block w-full text-sm" />
                <x-input-error :messages="$errors->get('settings_site_name')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="settings_site_slug" :value="__('Slug')" class="!text-xs" />
                <x-text-input id="settings_site_slug" wire:model="settings_site_slug" class="mt-1 block w-full font-mono text-sm" />
                <x-input-error :messages="$errors->get('settings_site_slug')" class="mt-1" />
                <p class="{{ $fieldHelp }}">{{ __('Lowercase letters, digits, and hyphens.') }}</p>
            </div>
        </div>
    </div>
</form>

{{-- Web directory — document_root. VM-only. --}}
@if (! $isContainerWorkspace)
<form wire:submit="saveWebDirectory" class="border-b border-brand-ink/10">
    <x-workspace-panel-head
        dense
        class="border-b border-brand-ink/10"
        icon="heroicon-o-folder"
        :title="$documentRootLabel"
        :note="__('Path nginx serves from. Webserver config re-applies on save.')"
    >
        <x-slot:actions>
            <button type="button" wire:click="rebuildWebserverConfig" wire:loading.attr="disabled" wire:target="rebuildWebserverConfig"
                class="{{ $btnOutline }}"
                title="{{ __('Re-apply this site’s nginx vhost — fixes a 502 caused by a missing/stale config without changing anything.') }}">
                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" aria-hidden="true" />
                <span wire:loading.remove wire:target="rebuildWebserverConfig">{{ __('Rebuild config') }}</span>
                <span wire:loading wire:target="rebuildWebserverConfig">{{ __('Rebuilding…') }}</span>
            </button>
            <x-primary-button size="sm" type="submit" wire:loading.attr="disabled" wire:target="saveWebDirectory">
                <span wire:loading.remove wire:target="saveWebDirectory">{{ __('Save') }}</span>
                <span wire:loading wire:target="saveWebDirectory">{{ __('Saving…') }}</span>
            </x-primary-button>
        </x-slot:actions>
    </x-workspace-panel-head>

    <div class="{{ $panelBody }}">
        <x-input-label for="settings_document_root" :value="$documentRootLabel" class="sr-only" />
        <x-text-input id="settings_document_root" wire:model="settings_document_root" class="block w-full font-mono text-sm" :placeholder="$documentRootPlaceholder" />
        <x-input-error :messages="$errors->get('settings_document_root')" class="mt-1" />
    </div>
</form>
@endif

{{-- Worker mode — VM / configurable hosts only. --}}
@if ($this->canConfigureWorkerMode)
<form wire:submit="saveWorkerMode" class="border-b border-brand-ink/10">
    <x-workspace-panel-head
        dense
        class="border-b border-brand-ink/10"
        icon="heroicon-o-server-stack"
        :title="__('Worker mode')"
        :note="__('Locks the public URL to a static workers page; webserver re-applies on save.')"
    >
        <x-slot:actions>
            <x-primary-button size="sm" type="submit" wire:loading.attr="disabled" wire:target="saveWorkerMode">
                <span wire:loading.remove wire:target="saveWorkerMode">{{ __('Save') }}</span>
                <span wire:loading wire:target="saveWorkerMode">{{ __('Saving…') }}</span>
            </x-primary-button>
        </x-slot:actions>
    </x-workspace-panel-head>

    <div class="{{ $panelBody }}">
        <label class="flex items-start gap-2.5">
            <input type="checkbox" wire:model.live="worker_mode" class="mt-0.5 h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage/30" />
            <span class="min-w-0">
                <span class="block text-xs font-semibold text-brand-ink">{{ __('Run this site as a worker (no web interface)') }}</span>
                <span class="mt-0.5 block text-[11px] text-brand-moss">
                    @if ($server->isWorkerHost())
                        {{ __('Worker hosts default this on. Turning it off lets this site serve HTTP.') }}
                    @else
                        {{ __('For queue/schedule-only sites. Leave off for a normal web app.') }}
                    @endif
                </span>
            </span>
        </label>
    </div>

    @if ($worker_mode)
        <div class="border-t border-brand-ink/10 {{ $panelBody }}">
            <x-input-label for="worker_page_html" :value="__('Custom worker page (optional)')" class="!text-xs" />
            <textarea id="worker_page_html" wire:model="worker_page_html" rows="5"
                class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                placeholder="<!doctype html>&#10;<html>&#10;  <body>&#10;    <h1>@{{site_name}} runs workers</h1>&#10;  </body>&#10;</html>"></textarea>
            <x-input-error :messages="$errors->get('worker_page_html')" class="mt-1" />
            <p class="{{ $fieldHelp }}">
                {{ __('Leave empty for the built-in page. Tokens:') }}
                <code class="rounded bg-brand-sand/40 px-1">@{{site_name}}</code>,
                <code class="rounded bg-brand-sand/40 px-1">@{{server_name}}</code>,
                <code class="rounded bg-brand-sand/40 px-1">@{{runtime}}</code>,
                <code class="rounded bg-brand-sand/40 px-1">@{{hostname}}</code>.
            </p>
        </div>
    @endif
</form>
@endif

{{-- Project / workspace assignment. --}}
<form wire:submit="saveProjectSettings" class="border-b border-brand-ink/10">
    <x-workspace-panel-head
        dense
        class="border-b border-brand-ink/10"
        icon="heroicon-o-folder-open"
        :title="$projectSettingsTitle"
        :note="$projectSettingsDescription"
    >
        <x-slot:actions>
            <x-primary-button size="sm" type="submit" wire:loading.attr="disabled" wire:target="saveProjectSettings">
                <span wire:loading.remove wire:target="saveProjectSettings">{{ __('Save project') }}</span>
                <span wire:loading wire:target="saveProjectSettings">{{ __('Saving…') }}</span>
            </x-primary-button>
        </x-slot:actions>
    </x-workspace-panel-head>

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
            <p class="{{ $fieldHelp }}">{{ __('Also manageable from the project resources page.') }}</p>
        </div>

        @if ($site->workspace)
            @feature('surface.projects')
                <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-3 py-1.5">
                    <p class="text-[11px] text-brand-moss">
                        {{ __('This site currently rolls up into :project.', ['project' => $site->workspace->name]) }}
                    </p>
                    <div class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-[11px] font-semibold">
                        <a href="{{ route('projects.resources', $site->workspace) }}" wire:navigate class="text-brand-forest hover:underline">{{ __('Resources') }}</a>
                        <a href="{{ route('projects.operations', $site->workspace) }}" wire:navigate class="text-brand-forest hover:underline">{{ __('Operations') }}</a>
                        <a href="{{ route('projects.delivery', $site->workspace) }}" wire:navigate class="text-brand-forest hover:underline">{{ __('Delivery') }}</a>
                    </div>
                </div>
            @endfeature
        @endif
    </div>
</form>

{{-- Site notes. --}}
<form wire:submit="saveSiteNotes" class="border-b border-brand-ink/10">
    <x-workspace-panel-head
        dense
        class="border-b border-brand-ink/10"
        icon="heroicon-o-pencil-square"
        :title="__('Site notes')"
        :note="__('Operational notes for handoff. Do not store secrets here.')"
    >
        <x-slot:actions>
            <x-primary-button size="sm" type="submit" wire:loading.attr="disabled" wire:target="saveSiteNotes">
                <span wire:loading.remove wire:target="saveSiteNotes">{{ __('Save') }}</span>
                <span wire:loading wire:target="saveSiteNotes">{{ __('Saving…') }}</span>
            </x-primary-button>
        </x-slot:actions>
    </x-workspace-panel-head>

    <div class="{{ $panelBody }}">
        <x-input-label for="site_notes" value="Notes" class="sr-only" />
        <textarea id="site_notes" wire:model="site_notes" rows="3" class="block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"></textarea>
        <x-input-error :messages="$errors->get('site_notes')" class="mt-1" />
    </div>
</form>

{{-- Error pages — VM webserver sites only. Header-only control. --}}
@if (! $isContainerWorkspace)
    @php $rawServerErrors = $this->serverErrorsExposed(); @endphp
    <div class="border-b border-brand-ink/10">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-exclamation-triangle"
            :title="__('When this site hits a 5xx')"
            :note="$rawServerErrors
                ? __('App renders its own 500/503 pages.')
                : __('Branded dply splash with a reference id for the Errors tab.')"
            :count="$rawServerErrors ? __('App errors') : __('Branded page')"
        >
            <x-slot:actions>
                @if ($rawServerErrors)
                    <button
                        type="button"
                        wire:click="hideServerErrors"
                        wire:loading.attr="disabled"
                        wire:target="hideServerErrors"
                        class="{{ $btnOutline }}"
                    >
                        <x-heroicon-o-shield-check class="h-3.5 w-3.5" aria-hidden="true" />
                        <span wire:loading.remove wire:target="hideServerErrors">{{ __('Use branded page') }}</span>
                        <span wire:loading wire:target="hideServerErrors">{{ __('Applying…') }}</span>
                    </button>
                @else
                    <button
                        type="button"
                        wire:click="exposeServerErrors"
                        wire:loading.attr="disabled"
                        wire:target="exposeServerErrors"
                        class="{{ $btnOutline }}"
                    >
                        <x-heroicon-o-code-bracket class="h-3.5 w-3.5" aria-hidden="true" />
                        <span wire:loading.remove wire:target="exposeServerErrors">{{ __('Let app handle errors') }}</span>
                        <span wire:loading wire:target="exposeServerErrors">{{ __('Applying…') }}</span>
                    </button>
                @endif
            </x-slot:actions>
        </x-workspace-panel-head>
    </div>
@endif

<div class="border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-2.5 sm:px-6">
    <x-cli-snippet :commands="[
        ['label' => __('Show site'), 'command' => 'dply sites:show '.$site->slug],
        ['label' => __('Rename site'), 'command' => 'dply sites:rename '.$site->slug.' <new-slug>'],
    ]" />
</div>

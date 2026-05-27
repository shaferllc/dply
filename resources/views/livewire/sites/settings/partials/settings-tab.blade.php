{{-- Site Settings tab — editable fields for things that don't have their own
     dedicated section. Primary hostname is intentionally NOT here — it's edited
     from Routing > Domains, which triggers the rename cascade modal. --}}

{{-- Site identity (display name + slug). Mirrors dply:site:rename semantics: row
     update only, on-disk path under /var/www/<slug> stays put. --}}
<section class="dply-card overflow-hidden">
    <form wire:submit="saveSiteIdentity">
        <div class="grid gap-0 lg:grid-cols-[17rem_minmax(0,1fr)]">
            <div class="border-b border-brand-ink/10 bg-brand-sand/15 p-6 lg:border-b-0 lg:border-r">
                <div class="flex items-start gap-3">
                    <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                        <x-heroicon-o-identification class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Site identity') }}</h2>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                            {{ __('Display name and URL slug for the site in dashboards and CLI. The on-disk deploy path under /var/www/<slug> is not renamed by this — keep deploys in mind before changing the slug.') }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="space-y-5 p-6 sm:p-8">
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
        </div>

        <div class="flex justify-end border-t border-brand-ink/10 bg-brand-sand/15 px-6 py-4 sm:px-8">
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
        <div class="grid gap-0 lg:grid-cols-[17rem_minmax(0,1fr)]">
            <div class="border-b border-brand-ink/10 bg-brand-sand/15 p-6 lg:border-b-0 lg:border-r">
                <div class="flex items-start gap-3">
                    <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                        <x-heroicon-o-folder class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ $documentRootLabel }}</h2>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                            {{ __('Path nginx serves from for this site. Webserver config re-applies on save.') }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="space-y-5 p-6 sm:p-8">
                <div>
                    <x-input-label for="settings_document_root" :value="$documentRootLabel" />
                    <x-text-input id="settings_document_root" wire:model="settings_document_root" class="mt-2 block w-full font-mono text-sm" :placeholder="$documentRootPlaceholder" />
                    <x-input-error :messages="$errors->get('settings_document_root')" class="mt-2" />
                </div>
            </div>
        </div>

        <div class="flex justify-end border-t border-brand-ink/10 bg-brand-sand/15 px-6 py-4 sm:px-8">
            <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
        </div>
    </form>
</section>
@endif

{{-- Project / workspace assignment — moved from General. --}}
<section class="dply-card mt-6 overflow-hidden">
    <form wire:submit="saveProjectSettings">
        <div class="grid gap-0 lg:grid-cols-[17rem_minmax(0,1fr)]">
            <div class="border-b border-brand-ink/10 bg-brand-sand/15 p-6 lg:border-b-0 lg:border-r">
                <div class="flex items-start gap-3">
                    <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                        <x-heroicon-o-folder-open class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ $projectSettingsTitle }}</h2>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                            {{ $projectSettingsDescription }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="space-y-5 p-6 sm:p-8">
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
        </div>

        <div class="flex justify-end border-t border-brand-ink/10 bg-brand-sand/15 px-6 py-4 sm:px-8">
            <x-primary-button type="submit">{{ __('Save project settings') }}</x-primary-button>
        </div>
    </form>
</section>

{{-- Site notes — moved from General. --}}
<section class="dply-card mt-6 overflow-hidden">
    <form wire:submit="saveSiteNotes">
        <div class="grid gap-0 lg:grid-cols-[17rem_minmax(0,1fr)]">
            <div class="border-b border-brand-ink/10 bg-brand-sand/15 p-6 lg:border-b-0 lg:border-r">
                <div class="flex items-start gap-3">
                    <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                        <x-heroicon-o-pencil-square class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Site notes') }}</h2>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                            {{ __('Keep operational notes here for details you want to save or hand off later. Avoid putting secrets or credentials in this field.') }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="space-y-4 p-6 sm:p-8">
                <div>
                    <x-input-label for="site_notes" value="Notes" />
                    <textarea id="site_notes" wire:model="site_notes" rows="5" class="mt-2 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"></textarea>
                    <x-input-error :messages="$errors->get('site_notes')" class="mt-2" />
                </div>
            </div>
        </div>

        <div class="flex justify-end border-t border-brand-ink/10 bg-brand-sand/15 px-6 py-4 sm:px-8">
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

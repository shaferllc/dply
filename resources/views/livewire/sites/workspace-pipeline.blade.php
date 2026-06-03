@php($isEmbedded = $embedded ?? false)
{{-- Single unconditional root element. This component is rendered embedded
     (nested) inside DeploymentsList, and Livewire requires a nested component's
     root to be ONE unconditional element. Choosing the root inside an
     @if/@else wraps it in <!--[if BLOCK]--> markers, which breaks Livewire's
     root detection — it re-assigns the component to a child element with a fresh
     id every render, so morph can't match and throws "Snapshot missing",
     destroying the component on every action. --}}
<div>
@if (! $isEmbedded)
<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    <div class="lg:grid lg:grid-cols-12 lg:gap-10">
        @include('livewire.sites.settings.partials.sidebar')

        <div class="min-w-0 lg:col-span-9">
            <x-breadcrumb-trail
                :items="$settingsBreadcrumbs"
                doc-contextual
                :contextual-doc-slug="$contextualDocSlug"
            />

            <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ $workspaceTitle }}</p>
            </div>

            @if ($headerRoleLabel !== null)
                <div class="mt-3 flex items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] ring-1 ring-inset {{ $headerRoleTone }}"
                          title="{{ __('Your access level for this :resource', ['resource' => strtolower($resourceNoun)]) }}">
                        @if ($headerIsDeployer)
                            <x-heroicon-m-rocket-launch class="h-3 w-3" aria-hidden="true" />
                        @elseif ($headerCanUpdateSite)
                            <x-heroicon-m-pencil-square class="h-3 w-3" aria-hidden="true" />
                        @else
                            <x-heroicon-m-eye class="h-3 w-3" aria-hidden="true" />
                        @endif
                        {{ $headerRoleLabel }}
                    </span>
                </div>
            @endif

            <x-page-header
                :title="$sectionHeader['title']"
                :description="$sectionDescription"
                :show-documentation="false"
                toolbar
                flush
                class="mt-3"
            >
                <x-slot name="leading">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                        @svg($sectionHeader['icon'], 'h-7 w-7 text-brand-ink')
                    </span>
                </x-slot>
            </x-page-header>

            <main class="min-w-0 space-y-6 mt-8">
@else
<div class="space-y-6">
@endif
                @if ($watchedConsoleRunId)
                    <div wire:poll.3s="resolveWatchedConsoleAction" class="hidden" aria-hidden="true"></div>
                @endif

                @include('livewire.sites.partials.pipeline._workspace-content')
@if (! $isEmbedded)
            </main>
        </div>
    </div>

    @include('livewire.partials.confirm-action-modal')
</div>
@else
</div>
@endif

    {{-- Outside the embedded gate: the pipeline page is rendered BOTH standalone
         and embedded (Deployments → Pipeline tab), and the rollout/step fields
         need a Save bar in both. It's position:fixed, so DOM placement doesn't
         matter — only that it stays inside the component root for $wire scope. --}}
    @unless ($site->usesEdgeRuntime() || ($functionsHost ?? $server->hostCapabilities()->supportsFunctionDeploy()))
        <x-unsaved-changes-bar
            :message="__('You have unsaved pipeline, step, or rollout changes.')"
            saveAction="savePipelineWorkspace"
            discardAction="discardPipelineWorkspaceUnsaved"
            :targets="$pipelineUnsavedTargets ?? null"
            form-pending-wire="pipeline_form_edits_pending"
            :client-dirty="true"
            :saveLabel="__('Save rollout')"
        />
    @endunless
</div>

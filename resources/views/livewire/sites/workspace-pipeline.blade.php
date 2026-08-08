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

            <section class="dply-card min-w-0 overflow-hidden p-0 mt-5">
                <x-workspace-panel-head
                    dense
                    class="border-b border-brand-ink/10"
                    :icon="$sectionHeader['icon']"
                    :title="$sectionHeader['title']"
                    :note="$sectionDescription"
                >
                    <x-slot:actions>
                        <a
                            href="{{ route('sites.deployments.index', [$server, $site]) }}"
                            wire:navigate
                            class="dply-btn dply-btn-xs dply-btn-outline"
                        >
                            {{ __('Open deployments') }}
                            <x-heroicon-m-arrow-right class="h-3 w-3" aria-hidden="true" />
                        </a>
                        @include('livewire.sites.partials.header-role-badge')
                    </x-slot:actions>
                </x-workspace-panel-head>

                <main class="min-w-0">
@else
{{-- Nested inside Deployments merged card — no outer spacing / second card. --}}
<div class="min-w-0">
@endif
                @if ($watchedConsoleRunId)
                    <div wire:poll.3s="resolveWatchedConsoleAction" class="hidden" aria-hidden="true"></div>
                @endif

                @include('livewire.sites.partials.pipeline._workspace-content')
@if (! $isEmbedded)
                </main>
            </section>
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

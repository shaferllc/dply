@php
    $card = 'rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden';
    $opsReady = $server->isReady() && $server->ssh_private_key;
@endphp

<x-server-workspace-layout
    :server="$server"
    active="deploy"
    :title="__('Deploy')"
    :description="__('Manage sites, databases, automation, and deploy tools for this server.')"
>
    @include('livewire.servers.partials.workspace-flashes', ['command_output' => $command_output ?? null, 'command_error' => $command_error ?? null])
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @if ($server->workspace)
        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 px-5 py-4 text-sm text-brand-ink">
            <p class="font-semibold">{{ __('Project delivery shortcut') }}</p>
            <p class="mt-1 leading-relaxed text-brand-moss">
                {{ __('If this server hosts sites that should release together, use the project delivery page to queue shared deploy batches, review project variables, and coordinate rollouts across the whole stack.') }}
            </p>
            <div class="mt-3 flex flex-wrap gap-3">
                <a href="{{ route('projects.delivery', $server->workspace) }}" wire:navigate class="text-sm font-medium text-brand-ink hover:text-brand-sage">{{ __('Open project delivery') }}</a>
                <a href="{{ route('projects.resources', $server->workspace) }}" wire:navigate class="text-sm font-medium text-brand-ink hover:text-brand-sage">{{ __('Open project resources') }}</a>
            </div>
        </div>
    @endif

    @if ($opsReady)
        <div class="space-y-8">
            @include('livewire.servers.partials.remote-ssh-stream-panel', ['logViewportLines' => 18])
            <div class="{{ $card }} p-6 sm:p-8">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Deploy') }}</h2>
                @if ($server->deploy_command)
                    <button type="button" wire:click="deploy" class="mt-4 inline-flex rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream hover:bg-brand-forest">{{ __('Deploy') }}</button>
                    <p class="mt-2 text-sm text-brand-moss">{{ __('Runs the configured deploy command.') }}</p>
                @else
                    <p class="mt-2 text-sm text-brand-moss">{{ __('No deploy command set. Add one below.') }}</p>
                @endif
            </div>
            <div class="{{ $card }} p-6 sm:p-8">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Deploy command') }}</h2>
                @php $deployTemplates = config('deploy_templates.templates', []); @endphp
                @if (count($deployTemplates) > 0)
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach ($deployTemplates as $templateKey => $template)
                            <button type="button" wire:click="applyDeployTemplate('{{ $templateKey }}')" class="rounded-lg border border-brand-ink/10 bg-brand-sand/25 px-3 py-1.5 text-sm hover:bg-brand-sand/45">{{ $template['name'] }}</button>
                        @endforeach
                    </div>
                @endif
                <form wire:submit="updateDeployCommand" class="mt-5">
                    <textarea wire:model="deploy_command" rows="3" class="w-full rounded-lg border border-brand-ink/15 font-mono text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30" placeholder="cd /var/www && git pull"></textarea>
                    <x-primary-button type="submit" class="mt-4">{{ __('Save deploy command') }}</x-primary-button>
                </form>
            </div>
            <div class="{{ $card }} p-6 sm:p-8">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Run command') }}</h2>
                <form wire:submit="runCommand" class="mt-4 flex flex-col gap-3 sm:flex-row">
                    <input type="text" wire:model="command" placeholder="uptime" class="flex-1 rounded-lg border border-brand-ink/15 px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30" required />
                    <x-primary-button type="submit" class="shrink-0">{{ __('Run') }}</x-primary-button>
                </form>
            </div>
        </div>
    @else
        <div class="rounded-2xl border border-brand-gold/40 bg-brand-sand/40 px-5 py-4 text-sm text-brand-olive">
            {{ __('Provisioning and SSH must be ready before you can use this section.') }}
        </div>
    @endif

    <x-slot name="modals">
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>

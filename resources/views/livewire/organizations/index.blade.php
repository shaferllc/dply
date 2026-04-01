<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
        <x-page-header
            :title="__('Organizations')"
            :description="__('Switch between workspaces, review usage at a glance, and jump into the right organization shell.')"
            flush
        >
            <x-slot name="actions">
                <a href="{{ route('organizations.create') }}" class="inline-flex items-center justify-center rounded-xl bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream shadow-md shadow-brand-ink/15 transition-colors hover:bg-brand-forest">
                    {{ __('New organization') }}
                </a>
            </x-slot>
        </x-page-header>

        <div>
            @if (session('success'))
                <x-alert tone="success" class="mb-4">{{ session('success') }}</x-alert>
            @endif
            @if ($organizations->isEmpty())
                <x-empty-state
                    :title="__('You\'re not in any organization yet.')"
                    :description="__('Create one to manage servers and billing.')"
                    :dashed="false"
                >
                    <x-slot name="actions">
                        <a href="{{ route('organizations.create') }}" class="text-sm font-semibold text-brand-ink hover:underline">{{ __('Create your first organization') }}</a>
                    </x-slot>
                </x-empty-state>
            @else
                <x-section-card padding="none">
                    <ul class="divide-y divide-slate-200">
                        @foreach ($organizations as $org)
                            <li class="flex flex-col gap-4 px-6 py-5 transition-colors hover:bg-brand-sand/20 lg:flex-row lg:items-center lg:justify-between">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <a href="{{ route('organizations.show', $org) }}" class="font-medium text-brand-ink">{{ $org->name }}</a>
                                        @if (session('current_organization_id') == $org->id)
                                            <x-badge tone="accent" size="sm">{{ __('Current') }}</x-badge>
                                        @endif
                                    </div>
                                    <p class="mt-1 text-sm text-brand-moss">
                                        {{ __('Quick overview of members, teams, infrastructure, and app footprint.') }}
                                    </p>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <x-badge size="sm">
                                            {{ $org->users_count }} {{ Str::plural('member', $org->users_count) }}
                                        </x-badge>
                                        <x-badge size="sm">
                                            {{ $org->teams_count }} {{ Str::plural('team', $org->teams_count) }}
                                        </x-badge>
                                        <x-badge size="sm">
                                            {{ $org->servers_count }} {{ Str::plural('server', $org->servers_count) }}
                                        </x-badge>
                                        <x-badge size="sm">
                                            {{ $org->sites_count }} {{ Str::plural('site', $org->sites_count) }}
                                        </x-badge>
                                        <x-badge size="sm">
                                            {{ $org->workspaces_count }} {{ Str::plural('project', $org->workspaces_count) }}
                                        </x-badge>
                                    </div>
                                </div>
                                <div class="flex shrink-0 items-center gap-3 text-sm">
                                    @if (session('current_organization_id') != $org->id)
                                        <button type="button" wire:click="switchOrganization('{{ $org->id }}')" class="text-sm font-medium text-brand-moss hover:text-brand-ink hover:underline">{{ __('Switch') }}</button>
                                    @endif
                                    <a href="{{ route('organizations.show', $org) }}" class="inline-flex items-center gap-1 font-medium text-brand-ink hover:text-brand-sage hover:underline">
                                        {{ __('Overview') }}
                                        <span aria-hidden="true">→</span>
                                    </a>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </x-section-card>
            @endif
        </div>
    </div>
</div>

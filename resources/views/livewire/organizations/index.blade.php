<div>
    <header class="border-b border-slate-200 bg-white">
        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-slate-800 leading-tight">Organizations</h2>
                <a href="{{ route('organizations.create') }}" class="inline-flex items-center px-4 py-2 bg-slate-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-slate-700">
                    New organization
                </a>
            </div>
        </div>
    </header>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="mb-4 p-4 rounded-md bg-green-50 text-green-800">{{ session('success') }}</div>
            @endif
            @if ($organizations->isEmpty())
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-8 text-center text-slate-500">
                    <p class="mb-4">You're not in any organization yet. Create one to manage servers and billing.</p>
                    <a href="{{ route('organizations.create') }}" class="text-slate-700 font-medium hover:underline">Create your first organization</a>
                </div>
            @else
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <ul class="divide-y divide-slate-200">
                        @foreach ($organizations as $org)
                            <li class="flex flex-col gap-4 px-6 py-5 hover:bg-slate-50 lg:flex-row lg:items-center lg:justify-between">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <a href="{{ route('organizations.show', $org) }}" class="font-medium text-slate-900">{{ $org->name }}</a>
                                        @if (session('current_organization_id') == $org->id)
                                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium uppercase tracking-wide text-slate-600">
                                                {{ __('Current') }}
                                            </span>
                                        @endif
                                    </div>
                                    <p class="mt-1 text-sm text-slate-500">
                                        {{ __('Quick overview of members, teams, infrastructure, and app footprint.') }}
                                    </p>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">
                                            {{ $org->users_count }} {{ Str::plural('member', $org->users_count) }}
                                        </span>
                                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">
                                            {{ $org->teams_count }} {{ Str::plural('team', $org->teams_count) }}
                                        </span>
                                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">
                                            {{ $org->servers_count }} {{ Str::plural('server', $org->servers_count) }}
                                        </span>
                                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">
                                            {{ $org->sites_count }} {{ Str::plural('site', $org->sites_count) }}
                                        </span>
                                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">
                                            {{ $org->workspaces_count }} {{ Str::plural('project', $org->workspaces_count) }}
                                        </span>
                                    </div>
                                </div>
                                <div class="flex shrink-0 items-center gap-3 text-sm">
                                    @if (session('current_organization_id') != $org->id)
                                        <button type="button" wire:click="switchOrganization('{{ $org->id }}')" class="text-slate-600 hover:underline text-sm">Switch</button>
                                    @endif
                                    <a href="{{ route('organizations.show', $org) }}" class="inline-flex items-center gap-1 font-medium text-slate-700 hover:text-slate-900 hover:underline">
                                        {{ __('Overview') }}
                                        <span aria-hidden="true">→</span>
                                    </a>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
</div>

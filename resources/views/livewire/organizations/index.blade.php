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
                            <li class="flex items-center justify-between px-6 py-4 hover:bg-slate-50">
                                <div>
                                    <a href="{{ route('organizations.show', $org) }}" class="font-medium text-slate-900">{{ $org->name }}</a>
                                    <p class="text-sm text-slate-500">
                                        {{ $org->users_count }} {{ Str::plural('member', $org->users_count) }} · {{ $org->teams_count }} {{ Str::plural('team', $org->teams_count) }}
                                    </p>
                                </div>
                                <div class="flex items-center gap-2">
                                    @if (session('current_organization_id') == $org->id)
                                        <span class="text-xs text-slate-500 font-medium">Current</span>
                                    @else
                                        <button type="button" wire:click="switchOrganization('{{ $org->id }}')" class="text-slate-600 hover:underline text-sm">Switch</button>
                                    @endif
                                    <a href="{{ route('organizations.show', $org) }}" class="text-slate-600 hover:underline text-sm">Manage</a>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
</div>

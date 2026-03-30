<div>
    <header class="border-b border-slate-200 bg-white">
        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Status pages') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ __('Public status pages and incidents for your servers and sites—similar to other hosting panels.') }}</p>
        </div>
    </header>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">
            @if (session('success'))
                <div class="p-4 rounded-md bg-green-50 text-green-800 text-sm">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="p-4 rounded-md bg-red-50 text-red-800 text-sm">{{ session('error') }}</div>
            @endif

            @if (! $hasOrganization)
                <div class="bg-white border border-slate-200 rounded-lg p-6 text-slate-600 text-sm">
                    {{ __('Select an organization from the header to manage status pages.') }}
                </div>
            @else
                @can('create', App\Models\StatusPage::class)
                    <div class="bg-white border border-slate-200 shadow-sm rounded-lg p-6">
                        <h3 class="font-medium text-slate-900 mb-4">{{ __('New status page') }}</h3>
                        <form wire:submit="createPage" class="space-y-4 max-w-xl">
                            <div>
                                <x-input-label for="sp-name" :value="__('Name')" />
                                <x-text-input id="sp-name" wire:model="name" type="text" class="mt-1 block w-full" required placeholder="{{ __('e.g. Acme Production') }}" />
                                <x-input-error :messages="$errors->get('name')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="sp-desc" :value="__('Description (optional)')" />
                                <textarea id="sp-desc" wire:model="description" rows="2" class="mt-1 block w-full border-slate-300 rounded-md shadow-sm text-sm"></textarea>
                            </div>
                            <x-primary-button type="submit">{{ __('Create') }}</x-primary-button>
                        </form>
                    </div>
                @endcan

                <div>
                    <h3 class="font-medium text-slate-900 mb-3">{{ __('Your status pages') }}</h3>
                    @if ($pages->isEmpty())
                        <div class="bg-white border border-slate-200 rounded-lg p-8 text-center text-slate-500 text-sm">
                            {{ __('No status pages yet. Create one, then add servers or sites to monitor and publish incidents.') }}
                        </div>
                    @else
                        <ul class="bg-white border border-slate-200 rounded-lg divide-y divide-slate-200">
                            @foreach ($pages as $page)
                                <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-4 hover:bg-slate-50">
                                    <div>
                                        <a href="{{ route('status-pages.manage', $page) }}" class="font-medium text-slate-900 hover:text-slate-700">{{ $page->name }}</a>
                                        @if ($page->description)
                                            <p class="text-sm text-slate-500 mt-0.5">{{ $page->description }}</p>
                                        @endif
                                        <p class="text-xs text-slate-400 mt-1">
                                            {{ __('Public URL:') }}
                                            <a href="{{ route('status.public', $page) }}" target="_blank" class="text-slate-600 hover:underline">{{ url('/status/'.$page->slug) }}</a>
                                            @if (! $page->is_public)
                                                <span class="text-amber-600">{{ __('(hidden)') }}</span>
                                            @endif
                                        </p>
                                    </div>
                                    <a href="{{ route('status-pages.manage', $page) }}" class="text-sm font-medium text-slate-700 hover:text-slate-900">{{ __('Manage') }}</a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>

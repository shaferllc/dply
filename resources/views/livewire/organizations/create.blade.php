<div>
    <header class="border-b border-slate-200 bg-white">
        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">New organization</h2>
        </div>
    </header>
    <div class="py-12">
        <div class="max-w-xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <x-livewire-validation-errors />
                <form wire:submit="store">
                    <div>
                        <x-input-label for="name" value="Organization name" />
                        <x-text-input id="name" wire:model="name" type="text" class="mt-1 block w-full" required autofocus />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>
                    <div class="mt-6 flex gap-3">
                        <x-primary-button wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="store">Create organization</span>
                        <span wire:loading wire:target="store" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            Creating…
                        </span>
                    </x-primary-button>
                        <a href="{{ route('organizations.index') }}" class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-md font-semibold text-xs text-slate-700 uppercase tracking-widest hover:bg-slate-50">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

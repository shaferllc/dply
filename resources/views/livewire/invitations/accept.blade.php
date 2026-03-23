<div>
    @if ($invitation)
        <header class="border-b border-slate-200 bg-white">
            <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                <h2 class="font-semibold text-xl text-slate-800 leading-tight">Organization invitation</h2>
            </div>
        </header>
        <div class="py-12">
            <div class="max-w-xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <p class="text-slate-700 mb-4">
                        <strong>{{ $invitation->inviter?->name ?? 'Someone' }}</strong> has invited you to join
                        <strong>{{ $invitation->organization->name }}</strong> as a <strong>{{ $invitation->role }}</strong>.
                    </p>
                    <p class="text-sm text-slate-500 mb-6">This invitation expires {{ $invitation->expires_at->format('M j, Y') }}.</p>
                    <div class="flex flex-wrap gap-3">
                        <button
                            type="button"
                            wire:click="accept"
                            wire:loading.attr="disabled"
                            class="inline-flex items-center px-4 py-2 bg-slate-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-slate-700 disabled:opacity-70"
                        >
                            <span wire:loading.remove wire:target="accept">Accept</span>
                            <span wire:loading wire:target="accept">Accepting…</span>
                        </button>
                        <button
                            type="button"
                            wire:click="decline"
                            wire:loading.attr="disabled"
                            class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-md font-semibold text-xs text-slate-700 uppercase tracking-widest hover:bg-slate-50 disabled:opacity-70"
                        >
                            <span wire:loading.remove wire:target="decline">Decline</span>
                            <span wire:loading wire:target="decline">Declining…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

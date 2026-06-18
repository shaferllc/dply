@if (($pinnedNotes ?? collect())->isNotEmpty())
    <div class="rounded-2xl border border-brand-sage/30 bg-brand-sage/5 p-5 sm:p-6">
        <div class="mb-3 flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <x-heroicon-s-bookmark class="h-4 w-4 text-brand-forest" aria-hidden="true" />
                <h2 class="text-sm font-semibold text-brand-ink">{{ __('Pinned notes') }}</h2>
            </div>
            <a
                href="{{ route('servers.settings', ['server' => $server, 'section' => 'notes']) }}"
                wire:navigate
                class="text-xs font-medium text-brand-sage transition hover:text-brand-forest"
            >{{ __('All notes') }}</a>
        </div>

        <div class="space-y-3">
            @foreach ($pinnedNotes as $note)
                <div wire:key="pinned-note-{{ $note->id }}" class="rounded-xl border border-brand-ink/10 bg-white px-4 py-3">
                    <x-markdown :content="$note->body" />
                    <p class="mt-2 text-xs text-brand-moss">
                        {{ $note->creator ? __('Added by :name', ['name' => $note->creator->name]) : __('Added') }}
                        <span title="{{ $note->updated_at?->toDayDateTimeString() }}">{{ $note->updated_at?->diffForHumans() }}</span>
                    </p>
                </div>
            @endforeach
        </div>
    </div>
@endif

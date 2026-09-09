    @if ($parserErrors !== [])
        <div class="{{ $card }}">
            <x-workspace-panel-head
                class="border-b border-brand-ink/10"
                icon="heroicon-o-exclamation-triangle"
                :title="__('The cached .env has parse errors')"
                :note="__('These lines failed to parse and will not deploy as written.')"
                tone="danger"
            />
            <ul class="list-inside list-disc px-5 py-3.5 sm:px-6">
                @foreach ($parserErrors as $err)
                    <li class="font-mono text-xs text-rose-800">{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

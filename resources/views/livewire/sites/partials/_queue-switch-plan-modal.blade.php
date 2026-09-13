{{-- Confirm for a queue connection switch: the stop/start list comes from
     QueueWorkerPlan, the same object SetUpSiteQueueingJob carries out.
     Expects: $modalName, $title, $plan, $action, $confirmLabel, optional $note. --}}
<x-modal :name="$modalName" max-width="lg" focusable :label="$title">
    <div class="p-6">
        <h3 class="text-base font-semibold text-brand-ink">{{ $title }}</h3>
        <p class="mt-2 text-sm text-brand-moss">
            {{ __('dply pushes the .env, clears the config cache, then changes the workers so one of them reads :d.', ['d' => $plan->connection]) }}
        </p>
        @if (! empty($note))
            <p class="mt-2 text-sm text-amber-800">{{ $note }}</p>
        @endif
        <ul class="mt-3 divide-y divide-brand-ink/10 rounded-lg border border-brand-ink/10">
            @foreach ($plan->move as $unit)
                <li class="flex flex-wrap items-center gap-2 px-3 py-2 text-sm">
                    <span class="rounded-full bg-sky-50 px-1.5 py-0.5 text-2xs font-semibold text-sky-800">{{ __('To Supervisor') }}</span>
                    <span class="font-semibold text-brand-ink">{{ $unit->name }}</span>
                    <code class="ml-auto truncate font-mono text-xs text-brand-moss" title="{{ $unit->command }}">{{ $unit->command }}</code>
                </li>
            @endforeach
            @foreach ($plan->start as $program)
                <li class="flex flex-wrap items-center gap-2 px-3 py-2 text-sm">
                    <span class="rounded-full bg-emerald-50 px-1.5 py-0.5 text-2xs font-semibold text-emerald-800">{{ __('Start') }}</span>
                    <span class="font-semibold text-brand-ink">{{ $program->slug }}</span>
                    <code class="ml-auto truncate font-mono text-xs text-brand-moss" title="{{ $program->command }}">{{ $program->command }}</code>
                </li>
            @endforeach
            @if ($plan->createDefault)
                <li class="flex flex-wrap items-center gap-2 px-3 py-2 text-sm">
                    <span class="rounded-full bg-emerald-50 px-1.5 py-0.5 text-2xs font-semibold text-emerald-800">{{ __('Create') }}</span>
                    <span class="font-semibold text-brand-ink">{{ __('A queue:work worker on the default queue') }}</span>
                </li>
            @endif
            @foreach ($plan->stop as $program)
                <li class="flex flex-wrap items-center gap-2 px-3 py-2 text-sm">
                    <span class="rounded-full bg-amber-50 px-1.5 py-0.5 text-2xs font-semibold text-amber-800">{{ __('Stop') }}</span>
                    <span class="font-semibold text-brand-ink">{{ $program->slug }}</span>
                    <code class="ml-auto truncate font-mono text-xs text-brand-moss" title="{{ $program->command }}">{{ $program->command }}</code>
                </li>
            @endforeach
            @if (! $plan->changesAnything())
                <li class="px-3 py-2 text-sm text-brand-moss">{{ __('No worker changes — the running workers already read :d.', ['d' => $plan->connection]) }}</li>
            @endif
        </ul>
        <p class="mt-3 text-xs text-brand-mist">{{ __('New workers start before old ones stop. Stopped workers keep their settings — switching back starts them again.') }}</p>
        <div class="mt-5 flex justify-end gap-2">
            <x-secondary-button size="sm" type="button" x-on:click="$dispatch('close-modal', '{{ $modalName }}')">{{ __('Cancel') }}</x-secondary-button>
            <x-primary-button size="sm" type="button"
                wire:click="{{ $action }}"
                x-on:click="$dispatch('close-modal', '{{ $modalName }}')"
                wire:loading.attr="disabled" wire:target="{{ $action }}">
                {{ $confirmLabel }}
            </x-primary-button>
        </div>
    </div>
</x-modal>

@props([
    'message',
    'saveAction',
    'discardAction' => 'discardUnsavedChanges',
    /** Comma-separated Livewire property paths; omit for whole-component dirty state */
    'targets' => null,
    'saveDisabled' => false,
    'saveLabel' => null,
    'discardLabel' => null,
    /**
     * Livewire bool property (e.g. pipeline_form_edits_pending) for modal / conditional forms.
     * Uses Alpine x-bind alongside wire:dirty so the bar is not hidden when only the modal is dirty.
     */
    'formPendingWire' => null,
    /**
     * Track dirtiness fully client-side by diffing the targeted wire:model inputs
     * against their last-saved DOM values. Far more reliable than wire:dirty for
     * deferred checkboxes / selects (wire:dirty does not surface those here).
     * Requires `targets`; ORs in `formPendingWire` when set.
     */
    'clientDirty' => false,
])

@php
    $saveLabel = $saveLabel ?? __('Save');
    $discardLabel = $discardLabel ?? __('Discard');
    $useClientDirty = $clientDirty && filled($targets);
    $targetList = filled($targets)
        ? array_values(array_filter(array_map('trim', explode(',', (string) $targets))))
        : [];
@endphp

{{--
    Default `hidden` keeps the bar off until something is dirty.

    Two ways to flip it visible:
      • clientDirty: a SELF-CONTAINED inline Alpine tracker (config read from the
        data-* attributes below) diffs the targeted wire:model inputs against
        their last-saved values — reliable for deferred checkboxes/selects, which
        wire:dirty does not catch on this page. Inline x-data avoids any
        alpine:init registration-ordering race.
      • otherwise: Livewire wire:dirty.class for field edits + Alpine for modal
        forms (formPendingWire), each owning a SEPARATE show class so they never
        clobber one another.

    z-[110] sits above app modals (z-[100]) so the bar stays visible while editing in a modal.
--}}
<div
    {{ $attributes->class([
        'dply-unsaved-bar',
        'hidden',
        'pointer-events-auto fixed left-1/2 z-[110] w-[calc(100%-2rem)] max-w-3xl -translate-x-1/2 rounded-2xl border border-brand-mist/80 bg-white shadow-lg shadow-brand-forest/10',
        'bottom-24 sm:bottom-28',
    ]) }}
    @if ($useClientDirty)
        data-unsaved-targets='@json($targetList)'
        @if (filled($formPendingWire)) data-unsaved-pending-prop="{{ $formPendingWire }}" @endif
        x-data="{
            targets: [],
            pendingProp: null,
            dirty: false,
            initial: {},
            root: null,
            init() {
                try { this.targets = JSON.parse(this.$el.dataset.unsavedTargets || '[]'); } catch (e) { this.targets = []; }
                this.pendingProp = this.$el.dataset.unsavedPendingProp || null;
                this.root = this.$el.closest('[wire\\:id]') || document.body;
                this.$nextTick(() => this.snapshot());
                let on = () => this.recompute();
                this.root.addEventListener('input', on, true);
                this.root.addEventListener('change', on, true);
                if (window.Livewire) {
                    window.Livewire.hook('commit', ({ succeed }) => {
                        succeed(() => { if (this.root && this.root.isConnected) { this.$nextTick(() => this.snapshot()); } });
                    });
                }
            },
            modelName(el) {
                for (let i = 0; i < el.attributes.length; i++) {
                    let a = el.attributes[i];
                    if (a.name === 'wire:model' || a.name.indexOf('wire:model.') === 0) { return a.value; }
                }
                return null;
            },
            fields() {
                if (! this.root) { return []; }
                return Array.from(this.root.querySelectorAll('input, select, textarea')).filter((el) => {
                    let n = this.modelName(el);
                    return n && this.targets.includes(n);
                });
            },
            readValue(el) { return el.type === 'checkbox' ? el.checked : el.value; },
            snapshot() {
                this.initial = {};
                this.fields().forEach((el) => { this.initial[this.modelName(el)] = this.readValue(el); });
                this.recompute();
            },
            recompute() {
                this.dirty = this.fields().some((el) => this.initial[this.modelName(el)] !== this.readValue(el));
            },
            get show() {
                if (this.dirty) { return true; }
                return this.pendingProp ? !! this.$wire[this.pendingProp] : false;
            }
        }"
        x-bind:class="{ 'dply-unsaved-bar-visible': show }"
    @else
        @if (filled($targets))
            wire:target="{{ $targets }}"
            wire:dirty.class="dply-unsaved-bar-visible"
        @endif
        @if (filled($formPendingWire))
            {{-- Alpine drives its OWN show class (…-pending) so its object binding
                 never clobbers the wire:dirty.class (…-visible) that field/checkbox
                 edits set — both classes independently un-hide the bar. --}}
            x-data
            x-bind:class="{ 'dply-unsaved-bar-pending': $wire.{{ $formPendingWire }} }"
        @endif
    @endif
    role="region"
    aria-label="{{ __('Unsaved changes') }}"
>
    <div class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4 sm:px-5 sm:py-3.5">
        <p class="text-sm text-brand-moss">{{ $message }}</p>
        <div class="flex shrink-0 flex-wrap items-center justify-end gap-2 sm:gap-2.5">
            <button
                type="button"
                wire:click="{{ $discardAction }}"
                class="inline-flex items-center justify-center rounded-xl border border-brand-ink/20 bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 focus:outline-none focus:ring-2 focus:ring-brand-sage/40"
            >
                <span wire:loading.remove wire:target="{{ $discardAction }}">{{ $discardLabel }}</span>
                <span wire:loading wire:target="{{ $discardAction }}" class="opacity-80">{{ __('Resetting…') }}</span>
            </button>
            <button
                type="button"
                wire:click="{{ $saveAction }}"
                @disabled($saveDisabled)
                class="inline-flex items-center justify-center rounded-xl bg-brand-sage px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-sage/90 focus:outline-none focus:ring-2 focus:ring-brand-sage/50 disabled:cursor-not-allowed disabled:opacity-50"
            >
                <span wire:loading.remove wire:target="{{ $saveAction }}">{{ $saveLabel }}</span>
                <span wire:loading wire:target="{{ $saveAction }}" class="inline-flex items-center gap-1.5">{{ __('Saving…') }}</span>
            </button>
        </div>
    </div>
</div>

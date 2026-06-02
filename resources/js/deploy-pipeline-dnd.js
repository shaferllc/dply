/**
 * Drag-and-drop for site deploy pipeline steps + hooks (Sortable.js).
 * Use this.$wire (not a reactive Alpine property) to avoid Vue __v_raw proxy errors.
 *
 * Registered from the main bundle (resources/js/app.js) so the component is
 * defined before Alpine.start(). It must NOT self-register on `alpine:init`:
 * this file used to be lazily @vite'd inside the pipeline partial, which loads
 * after the main bundle has already fired `alpine:init`, so the listener never
 * ran and `x-data="deployPipelineWorkspace()"` threw "not defined" (cascading
 * into unrelated Alpine scopes on the same page).
 */
import Sortable from 'sortablejs';

export function registerDeployPipelineWorkspace(Alpine) {
    Alpine.data('deployPipelineWorkspace', () => ({
        buildSortable: null,
        releaseSortable: null,
        buildPaletteSortable: null,
        releasePaletteSortable: null,
        hookPaletteSortable: null,
        hookZoneSortables: [],
        morphHook: null,
        dropBusy: false,
        dropBusyLabel: 'Updating pipeline…',

        init() {
            this.boot();

            if (typeof window.Livewire?.hook === 'function') {
                const componentId = this.$wire?.$id;
                this.morphHook = ({ component }) => {
                    if (componentId && component.id === componentId) {
                        requestAnimationFrame(() => this.boot());
                    }
                };
                window.Livewire.hook('morph.updated', this.morphHook);
            }
        },

        boot() {
            this.teardown();

            this.buildSortable = this.createStepZone(this.$refs.buildSortZone, 'build');
            this.releaseSortable = this.createStepZone(this.$refs.releaseSortZone, 'release');
            this.buildPaletteSortable = this.createStepPalette(this.$refs.buildPalette);
            this.releasePaletteSortable = this.createStepPalette(this.$refs.releasePalette);
            this.hookPaletteSortable = this.createHookPalette(this.$refs.hookPalette);

            this.$el.querySelectorAll('[data-hook-drop-zone]').forEach((zone) => {
                const anchor = zone.dataset.hookDropZone;
                if (! anchor) {
                    return;
                }
                const sortable = this.createHookZone(zone, anchor);
                if (sortable) {
                    this.hookZoneSortables.push(sortable);
                }
            });
        },

        acceptsPaletteItem(toEl, dragEl) {
            if (! toEl || ! dragEl?.dataset) {
                return false;
            }

            if (dragEl.dataset.paletteHookKind) {
                return Boolean(toEl.dataset?.hookDropZone);
            }

            if (dragEl.dataset.paletteType) {
                return toEl.dataset?.pipelineDropZone === dragEl.dataset.palettePhase;
            }

            return false;
        },

        createStepZone(zone, phase) {
            if (! zone) {
                return null;
            }

            return Sortable.create(zone, {
                group: {
                    name: `pipeline-flow-${phase}`,
                    pull: true,
                    put: (to, _from, dragEl) => this.acceptsPaletteItem(to.el, dragEl),
                },
                animation: 150,
                draggable: '[data-pipeline-step-id]',
                filter: '[data-pipeline-no-drag], [data-timeline-fixed], [data-hook-drop-zone]',
                preventOnFilter: true,
                ghostClass: 'dply-pipeline-sortable-ghost',
                chosenClass: 'dply-pipeline-sortable-chosen',
                onAdd: (evt) => this.handlePaletteAdd(evt, zone, phase),
                onEnd: (evt) => {
                    if (evt.item?.dataset?.paletteType || evt.item?.dataset?.paletteHookKind) {
                        return;
                    }
                    if (evt.from === evt.to && evt.oldIndex !== evt.newIndex) {
                        this.persistStepOrder(phase);
                    }
                },
            });
        },

        createHookZone(zone, anchor) {
            return Sortable.create(zone, {
                group: {
                    name: 'pipeline-hooks',
                    pull: false,
                    put: (to, _from, dragEl) => this.acceptsPaletteItem(to.el, dragEl),
                },
                animation: 150,
                draggable: '[data-hook-reorder-id]',
                filter: '[data-pipeline-no-drag], [data-timeline-fixed]',
                preventOnFilter: true,
                ghostClass: 'dply-pipeline-sortable-ghost',
                chosenClass: 'dply-pipeline-sortable-chosen',
                onAdd: (evt) => this.handleHookPaletteAdd(evt, anchor),
            });
        },

        createStepPalette(palette) {
            if (! palette) {
                return null;
            }

            const phase = palette.querySelector('[data-palette-phase]')?.dataset?.palettePhase ?? 'build';

            return Sortable.create(palette, {
                group: {
                    name: `pipeline-flow-${phase}`,
                    pull: 'clone',
                    put: false,
                },
                sort: false,
                animation: 120,
                draggable: '[data-palette-type]',
                handle: '[data-palette-drag-handle]',
                filter: '[data-pipeline-no-drag]',
                preventOnFilter: true,
                forceFallback: true,
                fallbackTolerance: 3,
            });
        },

        createHookPalette(palette) {
            if (! palette) {
                return null;
            }

            return Sortable.create(palette, {
                group: {
                    name: 'pipeline-hooks',
                    pull: 'clone',
                    put: false,
                },
                sort: false,
                animation: 120,
                draggable: '[data-palette-hook-kind]',
                handle: '[data-palette-drag-handle]',
                filter: '[data-pipeline-no-drag]',
                preventOnFilter: true,
                forceFallback: true,
                fallbackTolerance: 3,
            });
        },

        async handlePaletteAdd(evt, zone, phase) {
            if (this.dropBusy) {
                evt.item?.remove();

                return;
            }

            const type = evt.item?.dataset?.paletteType;
            const itemPhase = evt.item?.dataset?.palettePhase;
            const paletteCommand = evt.item?.dataset?.paletteCommand?.trim() || null;
            if (! type || itemPhase !== phase) {
                evt.item?.remove();

                return;
            }
            const insertIndex = this.stepInsertIndex(zone, evt.newIndex);
            evt.item.remove();

            if (! this.$wire) {
                return;
            }

            this.setDropBusy(true, 'Adding step…');
            try {
                await this.$wire.addDeployPipelineStepFromPalette(
                    type,
                    Number(insertIndex),
                    phase,
                    paletteCommand,
                );
            } finally {
                this.setDropBusy(false);
            }
        },

        async handleHookPaletteAdd(evt, anchor) {
            if (this.dropBusy) {
                evt.item?.remove();

                return;
            }

            const kind = evt.item?.dataset?.paletteHookKind;
            if (! kind) {
                evt.item?.remove();

                return;
            }
            const dropZone = evt.to;
            const stepId = dropZone?.dataset?.hookAnchorStepId || null;
            evt.item.remove();

            if (! this.$wire) {
                return;
            }

            this.setDropBusy(true, 'Adding hook…');
            try {
                await this.$wire.addDeployPipelineHookFromPalette(kind, anchor, stepId);
            } finally {
                this.setDropBusy(false);
            }
        },

        setDropBusy(busy, label = 'Updating pipeline…') {
            this.dropBusy = busy;
            this.dropBusyLabel = label;
            const sortables = [
                this.buildSortable,
                this.releaseSortable,
                this.buildPaletteSortable,
                this.releasePaletteSortable,
                this.hookPaletteSortable,
                ...this.hookZoneSortables,
            ];
            sortables.forEach((sortable) => {
                if (sortable) {
                    sortable.option('disabled', busy);
                }
            });
        },

        stepInsertIndex(zone, newIndex) {
            const children = [...zone.children];
            let index = 0;
            for (let i = 0; i < newIndex && i < children.length; i++) {
                if (children[i].dataset?.pipelineStepId) {
                    index++;
                }
            }

            return index;
        },

        async persistStepOrder(phase) {
            const zone = phase === 'release' ? this.$refs.releaseSortZone : this.$refs.buildSortZone;
            if (! zone || ! this.$wire) {
                return;
            }
            const ids = [...zone.querySelectorAll('[data-pipeline-step-id]')]
                .map((el) => el.dataset.pipelineStepId)
                .filter(Boolean);
            if (! ids.length) {
                return;
            }

            this.setDropBusy(true, 'Updating order…');
            try {
                if (phase === 'release') {
                    await this.$wire.reorderDeployPipelineReleaseSteps(ids);
                } else {
                    await this.$wire.reorderDeployPipelineBuildSteps(ids);
                }
            } finally {
                this.setDropBusy(false);
            }
        },

        teardown() {
            this.buildSortable?.destroy();
            this.releaseSortable?.destroy();
            this.buildPaletteSortable?.destroy();
            this.releasePaletteSortable?.destroy();
            this.hookPaletteSortable?.destroy();
            this.hookZoneSortables.forEach((s) => s.destroy());
            this.buildSortable = null;
            this.releaseSortable = null;
            this.buildPaletteSortable = null;
            this.releasePaletteSortable = null;
            this.hookPaletteSortable = null;
            this.hookZoneSortables = [];
        },

        destroy() {
            this.teardown();
        },
    }));
}

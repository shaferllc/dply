/**
 * Drag-and-drop reorder for admin roadmap items (Sortable.js).
 */
import Sortable from 'sortablejs';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('roadmapAdminDnD', () => ({
        sortables: [],
        morphHook: null,

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
            this.sortables.forEach((sortable) => sortable.destroy());
            this.sortables = [];

            this.$el.querySelectorAll('[data-roadmap-sort-zone]').forEach((zone) => {
                const sortable = Sortable.create(zone, {
                    group: {
                        name: 'roadmap-admin-columns',
                        pull: true,
                        put: true,
                    },
                    animation: 150,
                    handle: '[data-roadmap-drag-handle]',
                    draggable: '[data-roadmap-item-id]',
                    filter: '[data-roadmap-empty-hint]',
                    preventOnFilter: true,
                    ghostClass: 'roadmap-sort-ghost',
                    chosenClass: 'roadmap-sort-chosen',
                    onEnd: () => this.persistColumns(),
                });

                this.sortables.push(sortable);
            });
        },

        persistColumns() {
            const columns = {};

            this.$el.querySelectorAll('[data-roadmap-sort-zone]').forEach((zone) => {
                const status = zone.dataset.roadmapSortZone;
                if (! status) {
                    return;
                }

                columns[status] = [...zone.querySelectorAll('[data-roadmap-item-id]')]
                    .map((element) => element.dataset.roadmapItemId)
                    .filter(Boolean);
            });

            this.$wire.syncRoadmapColumns(columns);
        },
    }));
});

<?php

namespace App\Livewire\Servers\Concerns;

use App\Models\Server;
use App\Models\ServerNote;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * Multi-note server notebook CRUD for the Settings → Notes tab.
 *
 * Notes are first-class {@see ServerNote} rows (see the create_server_notes
 * migration that replaced the legacy `meta['notes']` blob). Each mutation
 * stamps the acting user for the audit line and busts the {@see serverNotes}
 * computed cache. Editing is inline — one note at a time via {@see $editingNoteId}.
 *
 * Notes carry free-form tags and can be archived (a visible state, not a soft
 * delete — see {@see ServerNote}). The list is filtered by archive state, a
 * tag chip and a text search; all three are plain component state so the
 * filter strip needs no extra round trips.
 *
 * Companion traits: {@see ManagesServerNoteComments} (discussion threads) and
 * {@see ManagesServerNoteExports} (Markdown/JSON download).
 *
 * Relies on the host component also using {@see ManagesWorkspaceSettingsForm}
 * for {@see deployerCannotEditServerSettings()}.
 *
 * @property-read Collection<int, ServerNote> $serverNotes
 * @property-read array<string, int> $serverNoteCounts
 * @property-read array<int, array{tag: string, count: int}> $serverNoteTags
 */
trait ManagesServerNotes
{
    /** Compose box for a brand-new note. */
    public string $noteDraft = '';

    /** Comma-separated tag input paired with {@see $noteDraft}. */
    public string $noteDraftTags = '';

    /** ID of the note currently being edited inline, or null. */
    public ?string $editingNoteId = null;

    /** Working copy of the body while editing an existing note. */
    public string $editingNoteBody = '';

    /** Working copy of the tags while editing an existing note. */
    public string $editingNoteTags = '';

    /** Archive-state filter: active | archived | all. */
    public string $noteFilter = 'active';

    /** Free-text filter matched against the note body and its tags. */
    public string $noteSearch = '';

    /** Single-tag chip filter, or null for "any tag". */
    public ?string $noteTagFilter = null;

    /**
     * The notes matching the current filter strip.
     *
     * @return Collection<int, ServerNote>
     */
    #[Computed]
    public function serverNotes(): Collection
    {
        return $this->serverNotesQuery()
            ->with(['creator:id,name', 'editor:id,name', 'archiver:id,name'])
            ->withCount('comments')
            ->get();
    }

    /**
     * Row counts per archive state — drives the filter tab badges, and is what
     * tells the operator an archive exists at all.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function serverNoteCounts(): array
    {
        $active = $this->server->notes()->active()->count();
        $archived = $this->server->notes()->archived()->count();

        return [
            'active' => $active,
            'archived' => $archived,
            'all' => $active + $archived,
        ];
    }

    /**
     * Every tag in use on this server with its note count, most-used first.
     * Feeds both the filter chips and the compose-box autocomplete.
     *
     * @return array<int, array{tag: string, count: int}>
     */
    #[Computed]
    public function serverNoteTags(): array
    {
        return $this->server->notes()
            ->whereNotNull('tags')
            ->pluck('tags')
            ->flatten()
            ->filter(fn ($tag): bool => is_string($tag) && $tag !== '')
            ->countBy()
            ->sortByDesc(fn (int $count, string $tag): array => [$count, $tag])
            ->map(fn (int $count, string $tag): array => ['tag' => $tag, 'count' => $count])
            ->values()
            ->all();
    }

    public function addServerNote(): void
    {
        if ($this->guardServerNotesEdit()) {
            return;
        }

        $validated = $this->validate([
            'noteDraft' => ['required', 'string', 'max:10000'],
            'noteDraftTags' => ['nullable', 'string', 'max:400'],
        ]);

        $note = $this->server->notes()->create([
            'body' => trim($validated['noteDraft']),
            'tags' => ServerNote::parseTags($validated['noteDraftTags'] ?? null),
            'pinned' => false,
            'created_by_user_id' => auth()->id(),
            'updated_by_user_id' => auth()->id(),
        ]);

        $this->noteDraft = '';
        $this->noteDraftTags = '';
        $this->forgetServerNoteCaches();

        // The editor keeps its own copy of the text for the toolbar and the
        // character counter; tell it the compose box was emptied.
        $this->dispatch('markdown-editor-reset', property: 'noteDraft', value: '');
        $this->dispatch('tag-input-reset', property: 'noteDraftTags', value: '');

        // A new note is always active, so drop the operator back to a filter
        // that can actually show it.
        if ($this->noteFilter === 'archived') {
            $this->noteFilter = 'active';
        }

        $this->auditServerNote('server.note_added', $note->id);
        $this->toastSuccess(__('Note added.'));
    }

    public function startEditingServerNote(string $noteId): void
    {
        if ($this->guardServerNotesEdit()) {
            return;
        }

        $note = $this->findServerNote($noteId);
        if ($note === null) {
            return;
        }

        $this->editingNoteId = (string) $note->id;
        $this->editingNoteBody = $note->body;
        $this->editingNoteTags = implode(', ', $note->tagList());
        $this->resetErrorBag(['editingNoteBody', 'editingNoteTags']);
    }

    public function cancelEditingServerNote(): void
    {
        $this->editingNoteId = null;
        $this->editingNoteBody = '';
        $this->editingNoteTags = '';
        $this->resetErrorBag(['editingNoteBody', 'editingNoteTags']);
    }

    public function updateServerNote(): void
    {
        if ($this->guardServerNotesEdit() || $this->editingNoteId === null) {
            return;
        }

        $validated = $this->validate([
            'editingNoteBody' => ['required', 'string', 'max:10000'],
            'editingNoteTags' => ['nullable', 'string', 'max:400'],
        ]);

        $note = $this->findServerNote($this->editingNoteId);
        if ($note === null) {
            $this->cancelEditingServerNote();

            return;
        }

        $note->update([
            'body' => trim($validated['editingNoteBody']),
            'tags' => ServerNote::parseTags($validated['editingNoteTags'] ?? null),
            'updated_by_user_id' => auth()->id(),
        ]);

        $this->cancelEditingServerNote();
        $this->forgetServerNoteCaches();

        $this->auditServerNote('server.note_updated', $note->id);
        $this->toastSuccess(__('Note updated.'));
    }

    public function toggleServerNotePin(string $noteId): void
    {
        if ($this->guardServerNotesEdit()) {
            return;
        }

        $note = $this->findServerNote($noteId);
        if ($note === null) {
            return;
        }

        // Pinning promotes a note to the server overview; an archived note has
        // explicitly been taken out of circulation, so the two can't coexist.
        if ($note->isArchived() && ! $note->pinned) {
            $this->toastError(__('Restore this note before pinning it.'));

            return;
        }

        $note->update([
            'pinned' => ! $note->pinned,
            'updated_by_user_id' => auth()->id(),
        ]);

        $this->forgetServerNoteCaches();

        $this->auditServerNote($note->pinned ? 'server.note_pinned' : 'server.note_unpinned', $note->id);
        $this->toastSuccess($note->pinned ? __('Note pinned to the overview.') : __('Note unpinned.'));
    }

    public function archiveServerNote(string $noteId): void
    {
        if ($this->guardServerNotesEdit()) {
            return;
        }

        $note = $this->findServerNote($noteId);
        if ($note === null || $note->isArchived()) {
            return;
        }

        $note->update([
            'archived_at' => now(),
            'archived_by_user_id' => auth()->id(),
            // Archiving must clear the pin, otherwise the note keeps holding a
            // slot on the overview while being hidden from the notes list.
            'pinned' => false,
            'updated_by_user_id' => auth()->id(),
        ]);

        if ($this->editingNoteId === $noteId) {
            $this->cancelEditingServerNote();
        }
        $this->forgetServerNoteCaches();

        $this->auditServerNote('server.note_archived', $note->id);
        $this->toastSuccess(__('Note archived.'));
    }

    public function restoreServerNote(string $noteId): void
    {
        if ($this->guardServerNotesEdit()) {
            return;
        }

        $note = $this->findServerNote($noteId);
        if ($note === null || ! $note->isArchived()) {
            return;
        }

        $note->update([
            'archived_at' => null,
            'archived_by_user_id' => null,
            'updated_by_user_id' => auth()->id(),
        ]);

        $this->forgetServerNoteCaches();

        $this->auditServerNote('server.note_restored', $note->id);
        $this->toastSuccess(__('Note restored.'));
    }

    /**
     * Copy a note into a fresh draft — the fast path for "same runbook, new
     * incident" without re-typing the structure.
     */
    public function duplicateServerNote(string $noteId): void
    {
        if ($this->guardServerNotesEdit()) {
            return;
        }

        $note = $this->findServerNote($noteId);
        if ($note === null) {
            return;
        }

        $copy = $this->server->notes()->create([
            'body' => $note->body,
            'tags' => $note->tags,
            'pinned' => false,
            'created_by_user_id' => auth()->id(),
            'updated_by_user_id' => auth()->id(),
        ]);

        $this->forgetServerNoteCaches();

        if ($this->noteFilter === 'archived') {
            $this->noteFilter = 'active';
        }

        $this->auditServerNote('server.note_added', $copy->id);
        $this->startEditingServerNote((string) $copy->id);
        $this->toastSuccess(__('Note duplicated — editing the copy.'));
    }

    public function deleteServerNote(string $noteId): void
    {
        if ($this->guardServerNotesEdit()) {
            return;
        }

        $note = $this->findServerNote($noteId);
        if ($note === null) {
            return;
        }

        // Comments cascade at the DB level (see the server_note_comments
        // migration), so there is nothing to clean up here.
        $note->delete();

        if ($this->editingNoteId === $noteId) {
            $this->cancelEditingServerNote();
        }
        $this->forgetServerNoteCaches();

        $this->auditServerNote('server.note_deleted', $noteId);
        $this->toastSuccess(__('Note deleted.'));
    }

    public function setServerNoteFilter(string $filter): void
    {
        $this->noteFilter = in_array($filter, ['active', 'archived', 'all'], true) ? $filter : 'active';
        $this->cancelEditingServerNote();
        $this->forgetServerNoteCaches();
    }

    /** Click a tag chip to filter by it; click the active one again to clear. */
    public function filterServerNotesByTag(string $tag): void
    {
        $tag = ServerNote::normalizeTag($tag);
        $this->noteTagFilter = $this->noteTagFilter === $tag ? null : $tag;
        unset($this->serverNotes);
    }

    public function clearServerNoteFilters(): void
    {
        $this->noteFilter = 'active';
        $this->noteSearch = '';
        $this->noteTagFilter = null;
        unset($this->serverNotes);
    }

    public function updatedNoteSearch(): void
    {
        unset($this->serverNotes);
    }

    /** True when the list is narrowed — drives the "Clear filters" affordance. */
    public function serverNotesAreFiltered(): bool
    {
        return $this->noteFilter !== 'active'
            || trim($this->noteSearch) !== ''
            || $this->noteTagFilter !== null;
    }

    /**
     * Base query for the notes list with the filter strip applied. Shared with
     * {@see ManagesServerNoteExports} so a download matches what is on screen.
     *
     * @return HasMany<ServerNote, Server>
     */
    protected function serverNotesQuery(): HasMany
    {
        $query = $this->server->notes();

        match ($this->noteFilter) {
            'archived' => $query->archived(),
            'all' => null,
            default => $query->active(),
        };

        if ($this->noteTagFilter !== null && $this->noteTagFilter !== '') {
            $query->whereJsonContains('tags', $this->noteTagFilter);
        }

        $search = trim($this->noteSearch);
        if ($search !== '') {
            // LOWER(...) LIKE rather than ILIKE so the query is not tied to the
            // Postgres dialect; note bodies are small enough that this is fine.
            $term = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $inner) use ($term): void {
                $inner->whereRaw('LOWER(body) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(tags::text, \'\')) LIKE ?', [$term]);
            });
        }

        return $query;
    }

    /** Server-scoped lookup — never trust a note ID from the wire. */
    protected function findServerNote(string $noteId): ?ServerNote
    {
        return $this->server->notes()->whereKey($noteId)->first();
    }

    /** Bust every computed slice that a note mutation can invalidate. */
    protected function forgetServerNoteCaches(): void
    {
        unset($this->serverNotes, $this->serverNoteCounts, $this->serverNoteTags);
    }

    /**
     * Authorize + block deployers from mutating notes. Returns true when the
     * caller must bail (mirrors the other settings mutators' guard shape).
     */
    protected function guardServerNotesEdit(): bool
    {
        $this->authorize('update', $this->server);

        if ($this->deployerCannotEditServerSettings()) {
            $this->toastError(__('Deployers cannot change server notes.'));

            return true;
        }

        return false;
    }

    protected function auditServerNote(string $action, string $noteId): void
    {
        if ($this->server->organization) {
            audit_log($this->server->organization, auth()->user(), $action, $this->server, null, ['note_id' => $noteId]);
        }
    }
}

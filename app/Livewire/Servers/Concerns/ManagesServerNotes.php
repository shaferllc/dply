<?php

namespace App\Livewire\Servers\Concerns;

use App\Models\ServerNote;
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
 * Relies on the host component also using {@see ManagesWorkspaceSettingsForm}
 * for {@see deployerCannotEditServerSettings()}.
 */
trait ManagesServerNotes
{
    /** Compose box for a brand-new note. */
    public string $noteDraft = '';

    /** ID of the note currently being edited inline, or null. */
    public ?string $editingNoteId = null;

    /** Working copy of the body while editing an existing note. */
    public string $editingNoteBody = '';

    /**
     * @return Collection<int, ServerNote>
     */
    #[Computed]
    public function serverNotes(): Collection
    {
        return $this->server->notes()
            ->with(['creator:id,name', 'editor:id,name'])
            ->get();
    }

    public function addServerNote(): void
    {
        if ($this->guardServerNotesEdit()) {
            return;
        }

        $validated = $this->validate([
            'noteDraft' => ['required', 'string', 'max:10000'],
        ]);

        $note = $this->server->notes()->create([
            'body' => trim($validated['noteDraft']),
            'pinned' => false,
            'created_by_user_id' => auth()->id(),
            'updated_by_user_id' => auth()->id(),
        ]);

        $this->noteDraft = '';
        unset($this->serverNotes);

        $this->auditServerNote('server.note_added', $note->id);
        $this->toastSuccess(__('Note added.'));
    }

    public function startEditingServerNote(string $noteId): void
    {
        if ($this->guardServerNotesEdit()) {
            return;
        }

        $note = $this->server->notes()->whereKey($noteId)->first();
        if ($note === null) {
            return;
        }

        $this->editingNoteId = (string) $note->id;
        $this->editingNoteBody = $note->body;
        $this->resetErrorBag('editingNoteBody');
    }

    public function cancelEditingServerNote(): void
    {
        $this->editingNoteId = null;
        $this->editingNoteBody = '';
        $this->resetErrorBag('editingNoteBody');
    }

    public function updateServerNote(): void
    {
        if ($this->guardServerNotesEdit() || $this->editingNoteId === null) {
            return;
        }

        $validated = $this->validate([
            'editingNoteBody' => ['required', 'string', 'max:10000'],
        ]);

        $note = $this->server->notes()->whereKey($this->editingNoteId)->first();
        if ($note === null) {
            $this->cancelEditingServerNote();

            return;
        }

        $note->update([
            'body' => trim($validated['editingNoteBody']),
            'updated_by_user_id' => auth()->id(),
        ]);

        $this->cancelEditingServerNote();
        unset($this->serverNotes);

        $this->auditServerNote('server.note_updated', $note->id);
        $this->toastSuccess(__('Note updated.'));
    }

    public function toggleServerNotePin(string $noteId): void
    {
        if ($this->guardServerNotesEdit()) {
            return;
        }

        $note = $this->server->notes()->whereKey($noteId)->first();
        if ($note === null) {
            return;
        }

        $note->update([
            'pinned' => ! $note->pinned,
            'updated_by_user_id' => auth()->id(),
        ]);

        unset($this->serverNotes);

        $this->auditServerNote($note->pinned ? 'server.note_pinned' : 'server.note_unpinned', $note->id);
        $this->toastSuccess($note->pinned ? __('Note pinned to the overview.') : __('Note unpinned.'));
    }

    public function deleteServerNote(string $noteId): void
    {
        if ($this->guardServerNotesEdit()) {
            return;
        }

        $note = $this->server->notes()->whereKey($noteId)->first();
        if ($note === null) {
            return;
        }

        $note->delete();

        if ($this->editingNoteId === $noteId) {
            $this->cancelEditingServerNote();
        }
        unset($this->serverNotes);

        $this->auditServerNote('server.note_deleted', $noteId);
        $this->toastSuccess(__('Note deleted.'));
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

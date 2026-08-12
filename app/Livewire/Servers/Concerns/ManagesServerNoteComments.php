<?php

namespace App\Livewire\Servers\Concerns;

use App\Models\ServerNote;
use App\Models\ServerNoteComment;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * Discussion threads under a server note — "we tried this, it didn't stick",
 * "superseded by the new runbook". Comments hang off {@see ServerNote} and are
 * deliberately flat: the note is the thread root.
 *
 * Threads are collapsed by default and loaded only for the notes the operator
 * expanded ({@see $openNoteComments}), so a notebook with hundreds of comments
 * costs one extra query for the counts and nothing more.
 *
 * Companion to {@see ManagesServerNotes}, whose {@see guardServerNotesEdit()}
 * and {@see auditServerNote()} this reuses.
 *
 * @property-read Collection<string, Collection<int, ServerNoteComment>> $serverNoteComments
 */
trait ManagesServerNoteComments
{
    /**
     * IDs of the notes whose comment thread is expanded.
     *
     * @var array<int, string>
     */
    public array $openNoteComments = [];

    /**
     * Per-note comment compose boxes, keyed by note ID.
     *
     * @var array<string, string>
     */
    public array $commentDrafts = [];

    /** ID of the comment being edited inline, or null. */
    public ?string $editingCommentId = null;

    /** Working copy of the body while editing a comment. */
    public string $editingCommentBody = '';

    /**
     * Comments for the expanded threads only, grouped by note ID.
     *
     * @return Collection<string, Collection<int, ServerNoteComment>>
     */
    #[Computed]
    public function serverNoteComments(): Collection
    {
        if ($this->openNoteComments === []) {
            return collect();
        }

        return ServerNoteComment::query()
            ->whereIn('server_note_id', $this->openNoteComments)
            // Scope through the parent note so an ID from the wire can never
            // pull a thread off another organization's server.
            ->whereHas('note', fn ($q) => $q->where('server_id', $this->server->id))
            ->with(['creator:id,name', 'editor:id,name'])
            ->orderBy('created_at')
            ->get()
            ->groupBy('server_note_id');
    }

    /**
     * @return Collection<int, ServerNoteComment>
     */
    public function commentsForNote(string $noteId): Collection
    {
        return $this->serverNoteComments->get($noteId) ?? collect();
    }

    public function noteCommentsAreOpen(string $noteId): bool
    {
        return in_array($noteId, $this->openNoteComments, true);
    }

    public function toggleNoteComments(string $noteId): void
    {
        if ($this->noteCommentsAreOpen($noteId)) {
            $this->openNoteComments = array_values(array_diff($this->openNoteComments, [$noteId]));

            if ($this->editingCommentId !== null) {
                $this->cancelEditingNoteComment();
            }
        } else {
            $this->openNoteComments[] = $noteId;
        }

        unset($this->serverNoteComments);
    }

    public function addNoteComment(string $noteId): void
    {
        if ($this->guardServerNotesEdit()) {
            return;
        }

        $note = $this->findServerNote($noteId);
        if ($note === null) {
            return;
        }

        $this->validate([
            "commentDrafts.{$noteId}" => ['required', 'string', 'max:2000'],
        ], [
            "commentDrafts.{$noteId}.required" => __('Write a comment first.'),
            "commentDrafts.{$noteId}.max" => __('Comments are limited to 2,000 characters.'),
        ]);

        $comment = $note->comments()->create([
            'body' => trim((string) ($this->commentDrafts[$noteId] ?? '')),
            'created_by_user_id' => auth()->id(),
            'updated_by_user_id' => auth()->id(),
        ]);

        $this->commentDrafts[$noteId] = '';

        if (! $this->noteCommentsAreOpen($noteId)) {
            $this->openNoteComments[] = $noteId;
        }

        $this->forgetServerNoteCaches();
        unset($this->serverNoteComments);

        $this->auditServerNoteComment('server.note_comment_added', $note->id, $comment->id);
        $this->toastSuccess(__('Comment added.'));
    }

    public function startEditingNoteComment(string $commentId): void
    {
        if ($this->guardServerNotesEdit()) {
            return;
        }

        $comment = $this->findServerNoteComment($commentId);
        if ($comment === null) {
            return;
        }

        $this->editingCommentId = (string) $comment->id;
        $this->editingCommentBody = $comment->body;
        $this->resetErrorBag('editingCommentBody');
    }

    public function cancelEditingNoteComment(): void
    {
        $this->editingCommentId = null;
        $this->editingCommentBody = '';
        $this->resetErrorBag('editingCommentBody');
    }

    public function updateNoteComment(): void
    {
        if ($this->guardServerNotesEdit() || $this->editingCommentId === null) {
            return;
        }

        $validated = $this->validate([
            'editingCommentBody' => ['required', 'string', 'max:2000'],
        ]);

        $comment = $this->findServerNoteComment($this->editingCommentId);
        if ($comment === null) {
            $this->cancelEditingNoteComment();

            return;
        }

        $comment->update([
            'body' => trim($validated['editingCommentBody']),
            'updated_by_user_id' => auth()->id(),
        ]);

        $noteId = (string) $comment->server_note_id;
        $this->cancelEditingNoteComment();
        unset($this->serverNoteComments);

        $this->auditServerNoteComment('server.note_comment_updated', $noteId, $comment->id);
        $this->toastSuccess(__('Comment updated.'));
    }

    public function deleteNoteComment(string $commentId): void
    {
        if ($this->guardServerNotesEdit()) {
            return;
        }

        $comment = $this->findServerNoteComment($commentId);
        if ($comment === null) {
            return;
        }

        $noteId = (string) $comment->server_note_id;
        $comment->delete();

        if ($this->editingCommentId === $commentId) {
            $this->cancelEditingNoteComment();
        }

        $this->forgetServerNoteCaches();
        unset($this->serverNoteComments);

        $this->auditServerNoteComment('server.note_comment_deleted', $noteId, $commentId);
        $this->toastSuccess(__('Comment deleted.'));
    }

    /** Server-scoped lookup — never trust a comment ID from the wire. */
    protected function findServerNoteComment(string $commentId): ?ServerNoteComment
    {
        return ServerNoteComment::query()
            ->whereKey($commentId)
            ->whereHas('note', fn ($q) => $q->where('server_id', $this->server->id))
            ->first();
    }

    protected function auditServerNoteComment(string $action, string $noteId, string $commentId): void
    {
        if ($this->server->organization) {
            audit_log($this->server->organization, auth()->user(), $action, $this->server, null, [
                'note_id' => $noteId,
                'comment_id' => $commentId,
            ]);
        }
    }
}

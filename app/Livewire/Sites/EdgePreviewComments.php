<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\EdgePreviewComment;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Preview-site comments dashboard (C5). Lists comments left on a
 * specific preview deploy and lets operators reply/resolve.
 *
 * Note: this is the dashboard surface only. The on-page comment widget
 * (C3) that *creates* comments from inside the preview iframe is not
 * shipped yet — the model and this list exist so the rollout has a
 * stable read path the moment the widget lands.
 */
#[Layout('layouts.app')]
class EdgePreviewComments extends Component
{
    use DispatchesToastNotifications;

    public Server $server;

    public Site $site;

    public string $newCommentBody = '';

    public string $newCommentUrlPath = '/';

    public function mount(Server $server, Site $site): void
    {
        $this->authorize('view', $site);

        if (! $site->usesEdgeRuntime() || ! $site->isEdgePreview()) {
            abort(404);
        }

        $this->server = $server;
        $this->site = $site;
    }

    public function addComment(): void
    {
        $this->authorize('update', $this->site);

        $this->validate([
            'newCommentBody' => ['required', 'string', 'max:8000'],
            'newCommentUrlPath' => ['required', 'string', 'max:2048', 'regex:#^/[^\s]*$#'],
        ]);

        EdgePreviewComment::query()->create([
            'organization_id' => $this->site->organization_id,
            'site_id' => $this->site->id,
            'created_by_user_id' => auth()->id(),
            'selector' => null,
            'viewport_width' => null,
            'url_path' => trim($this->newCommentUrlPath),
            'body' => trim($this->newCommentBody),
        ]);

        $this->newCommentBody = '';
        $this->toastSuccess(__('Comment added.'));
    }

    public function toggleResolved(string $commentId): void
    {
        $this->authorize('update', $this->site);

        $comment = EdgePreviewComment::query()
            ->where('site_id', $this->site->id)
            ->whereKey($commentId)
            ->firstOrFail();

        $comment->update([
            'resolved_at' => $comment->resolved_at === null ? now() : null,
            'resolved_by_user_id' => $comment->resolved_at === null ? auth()->id() : null,
        ]);
    }

    public function deleteComment(string $commentId): void
    {
        $this->authorize('update', $this->site);

        EdgePreviewComment::query()
            ->where('site_id', $this->site->id)
            ->whereKey($commentId)
            ->delete();

        $this->toastSuccess(__('Comment deleted.'));
    }

    public function render(): View
    {
        $comments = EdgePreviewComment::query()
            ->where('site_id', $this->site->id)
            ->with(['createdBy:id,name,email', 'resolvedBy:id,name,email'])
            ->orderByDesc('created_at')
            ->get();

        return view('livewire.sites.edge-preview-comments', [
            'comments' => $comments,
        ]);
    }
}

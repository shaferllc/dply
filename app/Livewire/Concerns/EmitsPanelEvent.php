<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

/**
 * Pairs with the `<x-workspace-console-banner>` Blade component to give a workspace inline
 * "panel-side change" feedback — added a key, deleted a row, updated a label, etc. — using
 * the same green-console treatment as queued jobs surface for sync/drift, but without the
 * job/cache/poll plumbing.
 *
 * The component using this trait should:
 *   1. Call `emitPanelEvent($message, $lines)` after the panel-side action succeeds.
 *   2. In the workspace's banner block, when no queued banner is showing, render
 *      `<x-workspace-console-banner :status="completed" :message="$panel_event_message"
 *      :output="$panel_event_lines" dismiss-action="dismissPanelBanner" />`.
 *
 * Banner precedence is the consumer's job — this trait deliberately doesn't decide; some
 * workspaces may want panel events to win over completed sync banners (more recent action),
 * others may not.
 */
trait EmitsPanelEvent
{
    /**
     * Transcript lines for the most recent panel event. Cleared on dismiss.
     *
     * @var list<string>
     */
    public array $panel_event_lines = [];

    /** Headline text rendered above the transcript. */
    public string $panel_event_message = '';

    /**
     * Banner color treatment for this event. Mirrors the workspace-console-banner statuses:
     * 'completed' (emerald), 'failed' (rose), 'running'/'queued' (sky). Most callers will
     * stick with the default; pass 'failed' when the action threw or the output represents
     * an error transcript.
     */
    public string $panel_event_status = 'completed';

    /**
     * Push a transcript into the panel-event banner. Replaces any prior panel event — the
     * operator only ever sees the most recent one.
     *
     * @param  list<string>  $lines
     */
    protected function emitPanelEvent(string $message, array $lines, string $status = 'completed'): void
    {
        $this->panel_event_message = $message;
        $this->panel_event_lines = array_values(array_filter($lines, 'is_string'));
        $this->panel_event_status = $status;
    }

    public function dismissPanelBanner(): void
    {
        $this->panel_event_message = '';
        $this->panel_event_lines = [];
        $this->panel_event_status = 'completed';
    }
}

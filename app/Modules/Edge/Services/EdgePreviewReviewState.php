<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgePreviewComment;
use App\Models\EdgePreviewReviewApproval;
use App\Models\Site;
use App\Modules\Edge\Support\EdgePreviewPullRequestLink;

/**
 * Aggregates review status for a preview deploy — open threads, approvals,
 * and whether promote prerequisites are met.
 */
final class EdgePreviewReviewState
{
    /**
     * @return array{
     *   open_count: int,
     *   resolved_count: int,
     *   thread_count: int,
     *   approval_count: int,
     *   min_approvals: int,
     *   require_approval: bool,
     *   block_open_comments: bool,
     *   ready_to_promote: bool,
     *   pr_url: ?string,
     *   pr_number: ?int,
     *   branch: ?string,
     *   head_sha_short: ?string,
     *   approvals: list<array{id: string, user_name: string, note: ?string, created_at: string}>,
     * }
     */
    /** @return array<string, mixed> */
    public function forPreview(Site $preview): array
    {
        $edge = $preview->edgeMeta();
        $prNumber = $edge['preview_pr_number'] ?? null;
        if (is_string($prNumber) && ctype_digit($prNumber)) {
            $prNumber = (int) $prNumber;
        }

        $openCount = EdgePreviewComment::query()
            ->where('site_id', $preview->id)
            ->whereNull('resolved_at')
            ->count();

        $resolvedCount = EdgePreviewComment::query()
            ->where('site_id', $preview->id)
            ->whereNotNull('resolved_at')
            ->count();

        $threadCount = EdgePreviewComment::query()
            ->where('site_id', $preview->id)
            ->whereNull('parent_id')
            ->count();

        $approvals = EdgePreviewReviewApproval::query()
            ->where('site_id', $preview->id)
            ->with('user:id,name,email')
            ->orderBy('created_at')
            ->get();

        $minApprovals = max(1, (int) config('edge.preview_review.min_approvals', 1));
        $requireApproval = (bool) config('edge.preview_review.require_approval', false);
        $blockOpen = (bool) config('edge.preview_review.block_open_comments', true);

        $approvalOk = ! $requireApproval || $approvals->count() >= $minApprovals;
        $commentsOk = ! $blockOpen || $openCount === 0;
        $ready = $approvalOk && $commentsOk;

        $headSha = trim((string) ($edge['preview_head_sha'] ?? ''));

        return [
            'open_count' => $openCount,
            'resolved_count' => $resolvedCount,
            'thread_count' => $threadCount,
            'approval_count' => $approvals->count(),
            'min_approvals' => $minApprovals,
            'require_approval' => $requireApproval,
            'block_open_comments' => $blockOpen,
            'ready_to_promote' => $ready,
            'pr_url' => EdgePreviewPullRequestLink::forPreview($preview),
            'pr_number' => is_int($prNumber) ? $prNumber : null,
            'branch' => isset($edge['preview_branch']) ? (string) $edge['preview_branch'] : null,
            'head_sha_short' => $headSha !== '' ? substr($headSha, 0, 7) : null,
            'approvals' => $approvals->map(fn (EdgePreviewReviewApproval $row): array => [
                'id' => (string) $row->id,
                'user_name' => (string) ($row->user?->name ?: $row->user?->email ?: __('Reviewer')),
                'note' => is_string($row->note) && $row->note !== '' ? $row->note : null,
                'created_at' => $row->created_at->toDateTimeString() ?? '',
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed> $review
     * @return list<array{label: string, value: string, mono?: bool}>
     */
    /** @return array<string, mixed> */
    /**
     * @return array<int, array<string, string>>
     * @param  array<string, mixed> $review
     */
    public function confirmModalRows(array $review): array
    {
        $rows = [
            [
                'label' => (string) __('Open review comments'),
                'value' => (string) ((int) ($review['open_count'] ?? 0)),
            ],
            [
                'label' => (string) __('Review approvals'),
                'value' => sprintf(
                    '%d / %d',
                    (int) ($review['approval_count'] ?? 0),
                    (int) ($review['min_approvals'] ?? 1),
                ),
            ],
            [
                'label' => (string) __('Ready to promote'),
                'value' => ! empty($review['ready_to_promote'])
                    ? (string) __('Yes')
                    : (string) __('Not yet — resolve open comments or add approvals'),
            ],
        ];

        if (! empty($review['pr_number'])) {
            $rows[] = [
                'label' => (string) __('Pull request'),
                'value' => '#'.(int) $review['pr_number'],
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed> $review
     */
    public function promoteBlockedMessage(array $review): ?string
    {
        if (! empty($review['ready_to_promote'])) {
            return null;
        }

        $parts = [];
        if (! empty($review['block_open_comments']) && (int) ($review['open_count'] ?? 0) > 0) {
            $parts[] = trans_choice(
                ':count open comment|:count open comments',
                (int) $review['open_count'],
                ['count' => (int) $review['open_count']],
            );
        }

        if (! empty($review['require_approval']) && (int) ($review['approval_count'] ?? 0) < (int) ($review['min_approvals'] ?? 1)) {
            $parts[] = __('At least :n approval required before promote.', [
                'n' => (int) ($review['min_approvals'] ?? 1),
            ]);
        }

        return $parts !== []
            ? implode(' ', $parts)
            : __('Review is not ready to promote yet.');
    }
}

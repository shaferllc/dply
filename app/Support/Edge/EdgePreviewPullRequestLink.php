<?php

declare(strict_types=1);

namespace App\Support\Edge;

use App\Models\Site;

/**
 * Builds a browser URL for the pull request tied to an Edge preview site.
 */
final class EdgePreviewPullRequestLink
{
    public static function forPreview(Site $preview): ?string
    {
        $edge = $preview->edgeMeta();
        $prNumber = $edge['preview_pr_number'] ?? null;
        if (! is_int($prNumber) || $prNumber <= 0) {
            if (is_string($prNumber) && ctype_digit($prNumber)) {
                $prNumber = (int) $prNumber;
            } else {
                return null;
            }
        }

        $parentId = $edge['preview_parent_site_id'] ?? null;
        $parentId = is_scalar($parentId) ? trim((string) $parentId) : '';
        if ($parentId === '') {
            return null;
        }

        $parent = Site::query()->find($parentId);
        if ($parent === null) {
            return null;
        }

        $parsed = self::parseRepo($parent);
        if ($parsed === null) {
            return null;
        }

        [$owner, $repo] = $parsed;

        return sprintf('https://github.com/%s/%s/pull/%d', $owner, $repo, $prNumber);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function parseRepo(Site $parent): ?array
    {
        $repo = trim((string) ($parent->edgeMeta()['source']['repo'] ?? ''));
        if ($repo === '') {
            return null;
        }

        if (preg_match('~github\.com[:/]([^/]+)/([^/?#]+)~i', $repo, $matches) === 1) {
            return [$matches[1], self::stripGitSuffix($matches[2])];
        }

        if (str_contains($repo, '/')) {
            [$owner, $name] = explode('/', $repo, 2);

            return [trim($owner), self::stripGitSuffix(trim($name))];
        }

        return null;
    }

    private static function stripGitSuffix(string $name): string
    {
        return str_ends_with(strtolower($name), '.git')
            ? substr($name, 0, -4)
            : $name;
    }
}

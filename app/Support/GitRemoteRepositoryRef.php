<?php

namespace App\Support;

/**
 * Parsed Git remote for hosted provider APIs (GitHub, GitLab.com, Bitbucket Cloud).
 */
final class GitRemoteRepositoryRef
{
    public function __construct(
        public string $provider,
        public ?string $owner,
        public ?string $repo,
        public ?string $gitlabProjectPath = null,
    ) {}

    public static function parse(string $url, string $kind): ?self
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if ($kind === 'custom') {
            return new self('custom', null, null, null);
        }

        if ($kind === 'github') {
            if (preg_match('#^git@github\.com:([^/]+)/([^.\s]+?)(?:\.git)?$#i', $url, $m)) {
                return new self('github', $m[1], rtrim($m[2], '/'), null);
            }
            if (preg_match('~^https?://github\.com/([^/]+)/([^/\s?]+)~i', $url, $m)) {
                $repo = rtrim($m[2], '/');
                $repo = preg_replace('~\.git$~i', '', $repo);

                return new self('github', $m[1], $repo, null);
            }

            return null;
        }

        if ($kind === 'gitlab') {
            if (preg_match('#^git@gitlab\.com:([^.\s]+?)(?:\.git)?$#i', $url, $m)) {
                return new self('gitlab', null, null, trim($m[1], '/'));
            }
            if (preg_match('~^https?://gitlab\.com/([^?\s]+)~i', $url, $m)) {
                $path = trim($m[1], '/');
                $path = preg_replace('~\.git$~i', '', $path);

                return new self('gitlab', null, null, $path);
            }

            return null;
        }

        if ($kind === 'bitbucket') {
            if (preg_match('#^git@bitbucket\.org:([^/]+)/([^.\s]+?)(?:\.git)?$#i', $url, $m)) {
                return new self('bitbucket', $m[1], rtrim($m[2], '/'), null);
            }
            if (preg_match('~^https?://bitbucket\.org/([^/]+)/([^/\s?]+)~i', $url, $m)) {
                $repo = rtrim($m[2], '/');
                $repo = preg_replace('~\.git$~i', '', $repo);

                return new self('bitbucket', $m[1], $repo, null);
            }

            return null;
        }

        return null;
    }
}

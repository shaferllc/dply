<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Normalize stored repository identifiers into something {@code git clone} /
 * {@code git ls-remote} can fetch.
 *
 * Serverless (and a few other surfaces) persist GitHub repos as bare
 * {@code owner/name} shorthand. That form is not a valid clone target — git
 * treats it as a local path — so callers that talk to a remote must expand it
 * first.
 */
final class GitCloneUrl
{
    /**
     * Turn a bare {@code owner/name} repo shorthand into a clone-able GitHub
     * HTTPS URL. Anything already URL-shaped (https / git / ssh / scp) is
     * returned untouched.
     */
    public static function normalize(string $repositoryUrl): string
    {
        $repositoryUrl = trim($repositoryUrl);

        if ($repositoryUrl === '') {
            return '';
        }

        if (preg_match('#^(https?://|git://|ssh://|git@)#i', $repositoryUrl) === 1) {
            return $repositoryUrl;
        }

        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repositoryUrl) === 1) {
            return 'https://github.com/'.$repositoryUrl.'.git';
        }

        return $repositoryUrl;
    }
}

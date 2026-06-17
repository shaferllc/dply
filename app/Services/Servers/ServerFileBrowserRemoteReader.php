<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\SshConnection;
use App\Support\Servers\FileBrowserEntry;
use App\Support\Servers\FileBrowserFileRead;
use App\Support\Servers\FileBrowserListing;
use App\Support\Servers\FileBrowserPathPolicy;

/**
 * Read-side SSH operations for the file browser: list a directory, stat one
 * entry, read a file's content + metadata.
 *
 * Always runs as a specific Linux user — no root-or-fallback dance. The caller
 * chooses: deploy user (the server's ssh_user), root (for the server browser's
 * View-as-root toggle), or a site's effectiveSystemUser for the site browser.
 *
 * Binary detection uses `file -b --mime-type` plus a NUL-byte fallback over
 * the first 8 KiB of the file body.
 */
class ServerFileBrowserRemoteReader
{
    /**
     * List a directory, sorted dirs-first then alphabetically. Truncated to the
     * config'd entry cap; when $filter is provided, applied as a server-side
     * glob (single segment, no slashes).
     */
    public function list(
        Server $server,
        string $path,
        string $loginUser,
        ?string $filter = null,
    ): FileBrowserListing {
        $path = FileBrowserPathPolicy::normalize($path);
        $cap = (int) config('server_file_browser.listing_entry_cap', 2000);
        $timeout = (int) config('server_file_browser.ssh_timeout_seconds', 30);

        $listTarget = escapeshellarg($path);
        $cmd = "ls -lAH --time-style=+%s --color=never -- {$listTarget} 2>/dev/null; echo __DPLY_LS_DONE__; ls -lA --time-style=+%s --color=never -- {$listTarget} 2>/dev/null";

        if ($filter !== null && $filter !== '') {
            $sanitized = $this->sanitizeFilter($filter);
            $globTarget = escapeshellarg(rtrim($path, '/').'/*'.$sanitized.'*');
            $cmd = "ls -lAdH --time-style=+%s --color=never -- {$globTarget} 2>/dev/null; echo __DPLY_LS_DONE__; ls -lAd --time-style=+%s --color=never -- {$globTarget} 2>/dev/null";
        }

        $output = $this->runOnce($server, $loginUser, $cmd, $timeout);
        [$resolvedRaw, $linkRaw] = $this->splitMarker($output);

        $resolved = $this->parseLsLines($resolvedRaw);
        $linkInfo = $this->parseLsLines($linkRaw);
        $entries = $this->mergeListings($resolved, $linkInfo);

        usort($entries, function (FileBrowserEntry $a, FileBrowserEntry $b): int {
            $ad = $a->isDir();
            $bd = $b->isDir();
            if ($ad !== $bd) {
                return $ad ? -1 : 1;
            }

            return strcasecmp($a->name, $b->name);
        });

        $total = count($entries);
        $truncated = $total > $cap;
        if ($truncated) {
            $entries = array_slice($entries, 0, $cap);
        }

        return new FileBrowserListing(
            path: $path,
            entries: $entries,
            truncated: $truncated,
            totalCount: $total,
            filter: $filter,
        );
    }

    /**
     * Read a file's content + metadata, capped at $maxBytes. When the file is
     * larger than the cap, content is null and contentTruncated is true.
     */
    public function read(
        Server $server,
        string $path,
        int $maxBytes,
        string $loginUser,
    ): FileBrowserFileRead {
        $path = FileBrowserPathPolicy::normalize($path);
        $timeout = (int) config('server_file_browser.ssh_timeout_seconds', 30);
        $target = escapeshellarg($path);

        $statCmd = "stat -c '%s %Y' -- {$target} 2>/dev/null; echo __DPLY_STAT_DONE__; file -b --mime-type -- {$target} 2>/dev/null";
        $statOut = $this->runOnce($server, $loginUser, $statCmd, $timeout);
        [$statLine, $mimeLine] = $this->splitMarker($statOut, '__DPLY_STAT_DONE__');

        $statLine = trim($statLine);
        $mimeLine = trim($mimeLine);

        if ($statLine === '' || ! preg_match('/^(\d+)\s+(\d+)$/', $statLine, $m)) {
            throw new \RuntimeException('File not found or not readable: '.$path);
        }

        $size = (int) $m[1];
        $mtime = (int) $m[2];
        $mime = $mimeLine !== '' ? $mimeLine : 'application/octet-stream';

        if ($size > $maxBytes) {
            return new FileBrowserFileRead(
                path: $path,
                size: $size,
                mtime: $mtime,
                sha256: '',
                mime: $mime,
                isBinary: ! str_starts_with($mime, 'text/') && ! $this->mimeLooksTextual($mime),
                content: null,
                contentTruncated: true,
            );
        }

        $contentCmd = "sha256sum -- {$target} 2>/dev/null; echo __DPLY_HASH_DONE__; cat -- {$target}";
        $contentOut = $this->runOnce($server, $loginUser, $contentCmd, $timeout);
        [$hashLine, $body] = $this->splitMarker($contentOut, '__DPLY_HASH_DONE__');

        $hashLine = trim($hashLine);
        $sha256 = '';
        if ($hashLine !== '' && preg_match('/^([0-9a-f]{64})/', $hashLine, $hm)) {
            $sha256 = $hm[1];
        }

        return new FileBrowserFileRead(
            path: $path,
            size: $size,
            mtime: $mtime,
            sha256: $sha256,
            mime: $mime,
            isBinary: $this->detectBinary($body, $mime),
            content: $body,
            contentTruncated: false,
        );
    }

    /**
     * Stream a file's bytes to $sink in chunks. Used for downloads.
     *
     * @param  callable(string):void  $sink
     */
    public function streamDownload(
        Server $server,
        string $path,
        callable $sink,
        int $maxBytes,
        string $loginUser,
    ): void {
        $path = FileBrowserPathPolicy::normalize($path);
        $timeout = max(60, (int) config('server_file_browser.ssh_timeout_seconds', 30) * 4);
        $target = escapeshellarg($path);

        $ssh = $this->connect($server, $loginUser);
        try {
            $sent = 0;
            $ssh->execWithCallback("cat -- {$target}", function (string $chunk) use (&$sent, $sink, $maxBytes): void {
                if ($sent >= $maxBytes) {
                    return;
                }
                $remaining = $maxBytes - $sent;
                if (strlen($chunk) > $remaining) {
                    $chunk = substr($chunk, 0, $remaining);
                }
                $sent += strlen($chunk);
                $sink($chunk);
            }, $timeout);
        } finally {
            $ssh->disconnect();
        }
    }

    protected function runOnce(Server $server, string $loginUser, string $command, int $timeout): string
    {
        $ssh = $this->connect($server, $loginUser);
        try {
            return $ssh->exec($command, $timeout);
        } finally {
            $ssh->disconnect();
        }
    }

    protected function connect(Server $server, string $loginUser): SshConnection
    {
        $role = $loginUser === 'root'
            ? SshConnection::ROLE_RECOVERY
            : SshConnection::ROLE_OPERATIONAL;

        return new SshConnection($server, $loginUser, $role);
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function splitMarker(string $output, string $marker = '__DPLY_LS_DONE__'): array
    {
        $pos = strpos($output, $marker);
        if ($pos === false) {
            return [$output, ''];
        }

        return [substr($output, 0, $pos), substr($output, $pos + strlen($marker) + 1)];
    }

    /**
     * Parse the body of `ls -lA --time-style=+%s` (one entry per non-total line).
     *
     * @return array<string, array{type: string, size: int, mtime: int, mode: string, owner: string, group: string, link_target: ?string}>
     */
    protected function parseLsLines(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = rtrim($line);
            if ($line === '' || str_starts_with($line, 'total ')) {
                continue;
            }

            if (! preg_match('/^(\S+)\s+\d+\s+(\S+)\s+(\S+)\s+(\d+)\s+(\d+)\s+(.+)$/', $line, $m)) {
                continue;
            }

            $perms = $m[1];
            $owner = $m[2];
            $group = $m[3];
            $size = (int) $m[4];
            $mtime = (int) $m[5];
            $name = $m[6];

            $linkTarget = null;
            if (str_contains($name, ' -> ')) {
                [$name, $linkTarget] = explode(' -> ', $name, 2);
            }

            $type = match ($perms[0] ?? '') {
                '-' => 'file',
                'd' => 'dir',
                'l' => 'link',
                default => 'other',
            };

            $out[$name] = [
                'type' => $type,
                'size' => $size,
                'mtime' => $mtime,
                'mode' => $perms,
                'owner' => $owner,
                'group' => $group,
                'link_target' => $linkTarget,
            ];
        }

        return $out;
    }

    /**
     * Merge the link-info pass (`ls -lA`, leaves links as `l`) with the resolved
     * pass (`ls -lAH`, dereferences) so a symlink to a directory shows
     * type=link with linkTargetIsDir=true.
     *
     * @param  array<string, mixed> $resolved
     * @param  array<string, mixed> $linkInfo
     * @return list<FileBrowserEntry>
     */
    protected function mergeListings(array $resolved, array $linkInfo): array
    {
        $names = array_unique(array_merge(array_keys($resolved), array_keys($linkInfo)));
        $entries = [];

        foreach ($names as $name) {
            $r = $resolved[$name] ?? null;
            $l = $linkInfo[$name] ?? null;
            $primary = $l ?? $r;
            if ($primary === null) {
                continue;
            }

            $type = $primary['type'];
            $linkTarget = $primary['link_target'];
            $linkTargetIsDir = false;

            if ($type === 'link' && $r !== null) {
                $linkTargetIsDir = $r['type'] === 'dir';
            }

            $entries[] = new FileBrowserEntry(
                name: $name,
                type: $type,
                size: $primary['size'],
                mtime: $primary['mtime'],
                mode: $primary['mode'],
                owner: $primary['owner'],
                group: $primary['group'],
                linkTarget: $linkTarget,
                linkTargetIsDir: $linkTargetIsDir,
            );
        }

        return $entries;
    }

    protected function sanitizeFilter(string $filter): string
    {
        $filter = trim($filter);
        $filter = preg_replace('#[/\\\\\\\\\0\$`\\(\\)\\{\\}|<>;&]+#', '', $filter) ?? '';

        return mb_substr($filter, 0, 64);
    }

    protected function detectBinary(string $body, string $mime): bool
    {
        if (str_starts_with($mime, 'text/') || $this->mimeLooksTextual($mime)) {
            return false;
        }

        $head = substr($body, 0, 8192);

        return str_contains($head, "\0");
    }

    protected function mimeLooksTextual(string $mime): bool
    {
        $textualSuffixes = ['+json', '+xml', '+yaml'];
        foreach ($textualSuffixes as $s) {
            if (str_ends_with($mime, $s)) {
                return true;
            }
        }

        return in_array($mime, [
            'application/json',
            'application/xml',
            'application/x-yaml',
            'application/yaml',
            'application/javascript',
            'application/x-sh',
            'application/x-shellscript',
            'application/x-php',
            'application/x-httpd-php',
            'application/sql',
        ], true);
    }
}

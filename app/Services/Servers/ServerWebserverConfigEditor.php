<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\ConfigRevision;
use App\Models\Server;
use App\Models\User;
use App\Services\ConfigRevisions\ConfigRevisionContext;
use App\Services\ConfigRevisions\ConfigRevisionRecorder;

/**
 * ConfigRevision stream for server-level webserver config files edited in
 * the Webserver workspace (nginx.conf, Caddyfile, etc.).
 */
final class ServerWebserverConfigEditor
{
    public const KIND = 'server_webserver_file';

    public function __construct(
        protected ConfigRevisionRecorder $recorder,
    ) {}

    public function streamKey(Server $server, string $engine, string $path): string
    {
        return 'server:'.$server->id.':webserver:'.$engine.':'.hash('sha256', $path);
    }

    /**
     * @return array{path: string, engine: string, content: string}
     */
    /** @return array<string, mixed> */
    public function snapshotFor(string $engine, string $path, string $content): array
    {
        return [
            'engine' => $engine,
            'path' => $path,
            'content' => $content,
        ];
    }

    public function snapshotChecksumFor(string $engine, string $path, string $content): string
    {
        return $this->recorder->checksumFor($this->snapshotFor($engine, $path, $content));
    }

    /**
     * Baseline-on-first-save: snapshot the pre-edit buffer once per stream.
     */
    public function ensureBaseline(
        Server $server,
        string $engine,
        string $path,
        string $content,
        ?User $user = null,
    ): void {
        $streamKey = $this->streamKey($server, $engine, $path);

        if (ConfigRevision::query()->forStream($streamKey)->exists()) {
            return;
        }

        $this->recorder->capture(
            $streamKey,
            self::KIND,
            $this->snapshotFor($engine, $path, $content),
            new ConfigRevisionContext(
                server: $server,
                user: $user,
                summary: __('Baseline (auto-captured)'),
            ),
        );
    }

    public function recordWrite(
        Server $server,
        string $engine,
        string $path,
        string $content,
        ?User $user = null,
        ?string $summary = null,
    ): ?ConfigRevision {
        return $this->recorder->capture(
            $this->streamKey($server, $engine, $path),
            self::KIND,
            $this->snapshotFor($engine, $path, $content),
            new ConfigRevisionContext(
                server: $server,
                user: $user,
                summary: $summary ?? __('Saved from webserver config editor'),
            ),
        );
    }

    public function lookupRevision(Server $server, string $engine, string $path, string $revisionId): ?ConfigRevision
    {
        return ConfigRevision::query()
            ->whereKey($revisionId)
            ->where('stream_key', $this->streamKey($server, $engine, $path))
            ->first();
    }
}

<?php

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Site;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;

/**
 * Measures a site's on-disk footprint over SSH (`du` on the deploy path, plus
 * the hosting volume's size/used/free via `df`) and records it on the site's
 * meta (`meta.disk_usage`) so the Site details card shows a real number instead
 * of "Not recorded yet". Queued + streamed through a console action so the
 * page-top banner confirms the result.
 *
 * Best-effort: a path that doesn't exist yet (pre-first-deploy) or an
 * unreachable box surfaces a clear console message rather than crashing. Runs as
 * the site user — `du` reads the site's own files without needing root.
 */
class MeasureSiteDiskUsageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public function __construct(
        public string $siteId,
        public ?string $userId = null,
        public ?string $seededConsoleRunId = null,
    ) {
        $this->onQueue('dply-control');
    }

    protected function consoleSubject(): Model
    {
        return Site::findOrFail($this->siteId);
    }

    protected function consoleKind(): string
    {
        return 'disk_usage_measure';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(ExecuteRemoteTaskOnServer $exec): void
    {
        $site = Site::query()->with('server')->find($this->siteId);
        if (! $site) {
            return;
        }
        $server = $site->server;
        if ($server === null) {
            return;
        }

        $this->bindConsoleRunId($this->seededConsoleRunId);
        $emit = $this->beginConsoleAction();

        $path = rtrim($site->effectiveRepositoryPath(), '/');

        try {
            $emit->info(__('Measuring disk usage at :path…', ['path' => $path]));

            $output = $exec->runInlineBash($server, 'site-disk-usage', $this->probeScript($path), 180);
            $parsed = $this->parse($output->buffer);

            if (($parsed['MISSING'] ?? '') === '1') {
                $emit->warn(__('Nothing on disk yet — this site has not been deployed to :path.', ['path' => $path]));
                $this->completeConsoleAction();

                return;
            }

            $bytes = $this->intOrNull($parsed['BYTES'] ?? null);
            if ($bytes === null) {
                throw new \RuntimeException('du returned no byte count (raw: '.trim($output->buffer).').');
            }

            $files = $this->intOrNull($parsed['FILES'] ?? null);

            $meta = $site->meta;
            $meta['disk_usage'] = array_filter([
                'bytes' => $bytes,
                'files' => $files,
                'volume_total_bytes' => $this->intOrNull($parsed['VSIZE'] ?? null),
                'volume_used_bytes' => $this->intOrNull($parsed['VUSED'] ?? null),
                'volume_available_bytes' => $this->intOrNull($parsed['VAVAIL'] ?? null),
                'path' => $path,
                'measured_at' => Carbon::now()->toIso8601String(),
            ], fn ($v): bool => $v !== null);
            $site->forceFill(['meta' => $meta])->save();

            $emit->success(__(':size on disk:files.', [
                'size' => Number::fileSize($bytes),
                'files' => $files !== null ? ' across '.number_format($files).' files' : '',
            ]));
            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $emit->error($e->getMessage());
            $this->failConsoleAction($e->getMessage());

            Log::warning('MeasureSiteDiskUsageJob failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * One bash probe printing KEY=VALUE lines: total bytes (real allocated, not
     * apparent), file count, and the hosting volume's size/used/free. `2>/dev/null`
     * swallows per-subdir permission noise; `du`/`df` still print their totals on
     * a partial read so the pipeline keeps the number even when they exit non-zero.
     */
    private function probeScript(string $path): string
    {
        $target = escapeshellarg($path);
        $script = <<<'BASH'
            if [ ! -e __TARGET__ ]; then echo "DPLY_MISSING=1"; exit 0; fi
            echo "DPLY_BYTES=$(du -sB1 __TARGET__ 2>/dev/null | cut -f1)"
            echo "DPLY_FILES=$(find __TARGET__ -type f 2>/dev/null | wc -l | tr -d ' ')"
            echo "DPLY_VSIZE=$(df -B1 --output=size __TARGET__ 2>/dev/null | tail -n1 | tr -d ' ')"
            echo "DPLY_VUSED=$(df -B1 --output=used __TARGET__ 2>/dev/null | tail -n1 | tr -d ' ')"
            echo "DPLY_VAVAIL=$(df -B1 --output=avail __TARGET__ 2>/dev/null | tail -n1 | tr -d ' ')"
            BASH;

        return str_replace('__TARGET__', $target, $script);
    }

    /**
     * @return array<string, string>
     */
    private function parse(string $raw): array
    {
        $parsed = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            if (preg_match('/^DPLY_([A-Z]+)=(.*)$/', trim($line), $m)) {
                $parsed[$m[1]] = $m[2];
            }
        }

        return $parsed;
    }

    private function intOrNull(?string $value): ?int
    {
        return $value !== null && is_numeric($value) ? (int) $value : null;
    }
}

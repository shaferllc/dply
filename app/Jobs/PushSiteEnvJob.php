<?php

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Jobs\Middleware\SerializeServerSsh;
use App\Models\Site;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Sites\ReleaseEnvLinkChecker;
use App\Services\Sites\SiteEnvPusher;
use App\Services\Sites\SiteEnvPushScheduler;
use App\Services\Sites\SiteEnvRuntimeApplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Wraps {@see SiteEnvPusher::push()} in a console-action job so progress
 * streams into the page-top banner — same pattern as Sync and Load.
 *
 * One-in-flight-per-site via {@see ShouldBeUnique}: rapid-fire mutations
 * (e.g. bulk paste followed by a single edit) coalesce naturally — the
 * second dispatch is rejected by the queue uniqueness guard, and the
 * single in-flight job reads the latest cache state when it runs, so
 * every change still lands on the server.
 *
 * Errors fail the run and are surfaced in the banner; the editable cache
 * is preserved so the operator can retry from the manual Push button.
 *
 * Per-SERVER serialization via {@see SerializeServerSsh}: while ShouldBeUnique
 * stops a single site double-pushing, this stops many sites on the SAME box
 * opening concurrent SSH sessions and saturating it. Contended pushes release
 * and retry (not fail) until they get the server's SSH slot.
 */
class PushSiteEnvJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    /**
     * A contended {@see SerializeServerSsh} release counts as an attempt, so a
     * plain `tries` cap would exhaust while waiting for the SSH slot. Bound the
     * wait by time instead ({@see retryUntil}) and cap real failures with
     * {@see $maxExceptions} so a broken push fails once instead of re-SSHing.
     */
    public int $maxExceptions = 1;

    /**
     * Safety TTL on the per-site uniqueness lock. With the debounced dispatch
     * ({@see SiteEnvPushScheduler}) plus the SSH-slot wait,
     * the unique lock is held a while; keep this larger than {@see retryUntil}
     * so a long wait can't expire the lock and let a duplicate push slip in.
     */
    public int $uniqueFor = 600;

    /**
     * @param  string|null  $ephemeralIdentityToken  a cache key (NOT the secret)
     *                                               under which a customer-held org age identity was stashed for this single
     *                                               apply. The raw identity is never serialized into the job payload; handle()
     *                                               pulls-and-forgets it from the cache and passes it to the pusher.
     */
    public function __construct(
        public string $siteId,
        public ?string $userId = null,
        public ?string $seededConsoleRunId = null,
        public ?string $ephemeralIdentityToken = null,
    ) {}

    /** Cache-key prefix for a show-once ephemeral identity handed to a push. */
    public const EPHEMERAL_IDENTITY_CACHE_PREFIX = 'env-push:ephemeral-identity:';

    /**
     * Bound how long the job waits for the server's SSH slot. Releases from the
     * serialization middleware retry until this moment; a real handler error
     * hits {@see $maxExceptions} first and fails immediately.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(5);
    }

    /**
     * Serialize SSH pushes per target server. Resolved at runtime (the job only
     * carries the site id); skipped if the site/server vanished so the job can
     * still run and no-op cleanly.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $serverId = Site::query()->whereKey($this->siteId)->value('server_id');

        return $serverId !== null ? [new SerializeServerSsh((string) $serverId)] : [];
    }

    public function uniqueId(): string
    {
        return 'console-action:env_push:'.$this->siteId;
    }

    protected function consoleSubject(): Model
    {
        return Site::findOrFail($this->siteId);
    }

    protected function consoleKind(): string
    {
        return 'env_push';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(SiteEnvPusher $pusher): void
    {
        $site = Site::find($this->siteId);
        if (! $site) {
            return;
        }

        $this->bindConsoleRunId($this->seededConsoleRunId);
        $emit = $this->beginConsoleAction();

        try {
            $emit->step('push', __('Resolving server connection'));
            $emit->step('push', __('Writing .env to :path', ['path' => $site->effectiveEnvFilePath()]));

            // Pull-and-forget any customer-held identity stashed for this apply.
            // It lives in the cache (transient) keyed by a token carried in the
            // payload — never the raw key — and is dropped the instant it is read.
            // isset() (not !== null): a payload serialized before this property
            // existed leaves the typed property uninitialized on unserialize —
            // reading it with !== null would fatal. isset() degrades to "no identity".
            $ephemeralIdentity = isset($this->ephemeralIdentityToken)
                ? Cache::pull(self::EPHEMERAL_IDENTITY_CACHE_PREFIX.$this->ephemeralIdentityToken)
                : null;

            $path = $pusher->push($site, null, $ephemeralIdentity);

            // Make the write actually take effect on the running app: rebuild
            // cached config + reload (no-op for sites that read .env live). The
            // applier guards against applying a broken env — if it refuses, the
            // .env is still saved (so a mid-edit save isn't blocked) but the
            // last-good cached config keeps serving; surface that to the operator.
            try {
                $emit->step('push', __('Applying environment to the running app'));
                app(SiteEnvRuntimeApplier::class)->apply($site);
                $emit->success(__('.env written to :path and applied', ['path' => $path]));
            } catch (\Throwable $applyEx) {
                $emit->success(__('.env written to :path — not applied: :why', [
                    'path' => $path,
                    'why' => $applyEx->getMessage(),
                ]));
                Log::warning('PushSiteEnvJob: env written but not applied', [
                    'site_id' => $this->siteId,
                    'reason' => $applyEx->getMessage(),
                ]);
            }

            // Drift check: with a relocated env (shared/.env), every release's
            // .env should symlink to that one canonical file so this push reaches
            // them all. Warn if a release carries a real/divergent .env — a
            // rollback to it would serve stale secrets. Best-effort: never let a
            // probe failure fail the push.
            try {
                $this->warnOnReleaseEnvDrift($site, $emit);
            } catch (\Throwable $driftEx) {
                Log::warning('PushSiteEnvJob: release .env drift check failed', [
                    'site_id' => $this->siteId,
                    'reason' => $driftEx->getMessage(),
                ]);
            }

            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $emit->error($e->getMessage(), 'push');
            $this->failConsoleAction($e->getMessage());

            Log::warning('PushSiteEnvJob failed', [
                'site_id' => $this->siteId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Scan the atomic releases and surface a banner warning if any release's
     * .env has drifted off the shared canonical file. No-op for layouts where
     * each release legitimately owns its .env (see {@see ReleaseEnvLinkChecker}).
     */
    private function warnOnReleaseEnvDrift(Site $site, ConsoleEmitter $emit): void
    {
        $result = app(ReleaseEnvLinkChecker::class)->check($site);
        if (! $result['applicable'] || $result['drifted'] === []) {
            return;
        }

        $describe = static function (array $d): string {
            $label = match ($d['kind']) {
                'real_file' => __('real file'),
                'missing' => __('no .env'),
                'wrong_target' => __('→ :t', ['t' => (string) ($d['target'] ?? '?')]),
                default => $d['kind'],
            };

            return $d['release'].' ('.$label.')';
        };

        $names = array_map($describe, array_slice($result['drifted'], 0, 5));
        $more = count($result['drifted']) - count($names);
        $list = implode(', ', $names).($more > 0 ? __(' +:n more', ['n' => $more]) : '');

        $emit->warn(__(':n of :total release(s) do not symlink the shared .env (:canonical) — this push did not reach them, and a rollback to them would serve a stale or wrong .env. Re-deploy to relink: :list', [
            'n' => count($result['drifted']),
            'total' => $result['checked'],
            'canonical' => $result['canonical'] !== '' ? $result['canonical'] : $site->effectiveEnvFilePath(),
            'list' => $list,
        ]), 'push');
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;
use App\Modules\Queue\Actions\CreateQueueNamespace;
use App\Modules\Queue\Models\QueueNamespace;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Point a site's app at dply's managed queue.
 *
 * The namespace, the credential and the endpoint all existed before this — on
 * the org's Queues page, where creating one handed you environment variables to
 * copy into your app by hand. This closes the gap from the site's side: create
 * the namespace for THIS site, mint its token, and write the variables into the
 * env dply already manages.
 *
 * Nothing here edits the customer's code. The `dply` connection is registered
 * by the queue-insights package from these same variables, so the upgrade is
 * environment only: no config/queue.php diff to review, no AWS SDK, and no
 * collision with the AWS keys an app's S3 disk is already using.
 *
 * The token is returned, never stored — dply keeps a hash. It exists for one
 * render, which is why the caller has to show it.
 */
final class ManagedQueueConnector
{
    public function __construct(
        private readonly CreateQueueNamespace $create,
        private readonly DotEnvFileParser $parser,
        private readonly DotEnvFileWriter $writer,
    ) {}

    /**
     * @return array{namespace: QueueNamespace, token: string}
     */
    public function connect(Site $site, ?string $userId = null): array
    {
        $organization = $site->organization;

        if ($organization === null) {
            throw new RuntimeException('This site has no organization to bill a queue to.');
        }

        if ($this->namespaceFor($site) !== null) {
            throw new RuntimeException('This site already has a managed queue.');
        }

        $result = $this->create->handle($organization, $this->nameFor($site), $site, $userId);
        $namespace = $result['namespace'];

        $this->writeEnvironment($site, $result['plaintext']);

        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['managed_queue'] = [
            'namespace_id' => (string) $namespace->id,
            'connected_at' => now()->toIso8601String(),
        ];

        // The package registers the connection, so the site needs it installed.
        // Turning the agent on here rather than asking twice: an operator who
        // has just moved their queue onto dply is not choosing to run without
        // the piece that makes `QUEUE_CONNECTION=dply` resolve to anything.
        $meta['queue_insights'] = [
            'enabled' => true,
            'token' => (string) data_get($meta, 'queue_insights.token', '') ?: (string) Str::random(48),
        ];

        // An observation from before the switch would otherwise keep the
        // readiness panel insisting this site runs on redis.
        unset($meta['queue_observed']);

        $site->forceFill(['meta' => $meta])->save();

        return ['namespace' => $namespace, 'token' => $result['plaintext']];
    }

    /** The namespace serving this site, if it has one. */
    public function namespaceFor(Site $site): ?QueueNamespace
    {
        $id = (string) data_get($site->meta, 'managed_queue.namespace_id', '');

        if ($id !== '') {
            $found = QueueNamespace::query()->find($id);

            if ($found !== null) {
                return $found;
            }
        }

        // Fall back to the column: a namespace created from the org page with
        // this site selected belongs to it just as much as one created here.
        return QueueNamespace::query()->where('site_id', $site->id)->first();
    }

    /** Whether the platform can offer this at all. */
    public static function available(): bool
    {
        return (bool) config('queue_service.enabled', false)
            && trim((string) config('queue_service.public_url', '')) !== '';
    }

    public static function endpoint(): string
    {
        return rtrim((string) config('queue_service.public_url', ''), '/');
    }

    /**
     * Write the variables the connection needs.
     *
     * Into dply's copy, so the next deploy or env push carries them to the box —
     * the same path every other managed resource takes, rather than a bespoke
     * SSH write that could disagree with what the page shows.
     */
    private function writeEnvironment(Site $site, string $token): void
    {
        $existing = $this->parser->parse((string) ($site->env_file_content ?? ''));
        $variables = $existing['variables'];

        $variables['QUEUE_CONNECTION'] = 'dply';
        $variables['DPLY_QUEUE_URL'] = self::endpoint();
        $variables['DPLY_QUEUE_TOKEN'] = $token;
        // Failures go where the jobs go. Explicit rather than assumed by the
        // package: an app that wants to keep its own failed_jobs table only has
        // to change this one line back.
        $variables['QUEUE_FAILED_DRIVER'] = 'dply';

        $site->forceFill([
            'env_file_content' => $this->writer->render($variables, $existing['comments']),
            'env_cache_origin' => 'local-edit',
        ])->save();
    }

    private function nameFor(Site $site): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $site->name) ?: 'site';

        return mb_substr(trim((string) $name, '-'), 0, 60) ?: 'site';
    }
}

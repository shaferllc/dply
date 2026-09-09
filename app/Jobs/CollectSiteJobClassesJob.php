<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Catalogue every job class the deployed app could run.
 *
 * The queue store answers "what is waiting" and the agent answers "what ran".
 * Neither answers "what CAN run" — a site with an empty queue and no history
 * looks identical whether it has forty job classes or none. Only the codebase
 * knows, so this reads the codebase: the app's own PSR-4 roots from
 * composer.json, every class under them, kept if it implements `ShouldQueue`.
 *
 * Vendor is deliberately excluded. Framework internals implement ShouldQueue
 * too, and a catalogue of the customer's jobs buried in three hundred of
 * Laravel's is not a catalogue.
 *
 * Cached rather than stored: this is a property of the deployed release, and
 * the next deploy replaces it wholesale.
 */
class CollectSiteJobClassesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    /** A catalogue, not a code dump — enough for any real app, bounded for the pathological one. */
    public const LIMIT = 400;

    public function __construct(public string $siteId)
    {
        $this->onQueue('dply-control');
    }

    public static function cacheKey(string $siteId): string
    {
        return 'site:'.$siteId.':job-classes';
    }

    /**
     * @return array{jobs: list<array<string, mixed>>, truncated: bool, error: ?string, read_at: ?string}|null
     */
    public static function cached(string $siteId): ?array
    {
        $value = Cache::get(self::cacheKey($siteId));

        return is_array($value) ? $value : null;
    }

    public function handle(ExecuteRemoteTaskOnServer $exec): void
    {
        $site = Site::query()->with('server')->find($this->siteId);

        if ($site === null || $site->server === null || ! $site->server->isReady()) {
            return;
        }

        $dir = rtrim((string) $site->effectiveEnvDirectory(), '/');

        if ($dir === '') {
            return;
        }

        $payload = base64_encode((string) json_encode(['limit' => self::LIMIT]));
        $php = base64_encode($this->remotePhp());

        $bash = sprintf(
            'cd %s 2>/dev/null && DPLY_JC_IN=%s php -d error_reporting=0 -r "eval(base64_decode(\'%s\'));" 2>/dev/null || true',
            escapeshellarg($dir),
            escapeshellarg($payload),
            $php,
        );

        $result = null;

        try {
            $out = $exec->runInlineBash($site->server, 'site:job-classes', $bash, timeoutSeconds: 90, asRoot: false);
        } catch (\Throwable $e) {
            Log::info('job classes: exec failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
            $result = ['error' => 'Could not read the application: '.$e->getMessage()];
        }

        $result ??= $this->extract((string) $out->buffer);

        // Cache the failure too: the page has to stop saying "reading" whether
        // or not the box had anything to say.
        Cache::put(self::cacheKey($this->siteId), [
            'jobs' => array_values((array) ($result['jobs'] ?? [])),
            'truncated' => (bool) ($result['truncated'] ?? false),
            'error' => $result['error'] ?? null,
            'read_at' => now()->toIso8601String(),
        ], now()->addMinutes(30));
    }

    /**
     * Walk the app's own namespaces and reflect what it finds.
     *
     * Reflection, not a source parse: `$queue`, `$tries` and `$timeout` are as
     * often set on a base class or in a trait as on the job itself, and a regex
     * over one file sees none of that. The cost is autoloading each candidate,
     * which is why only PSR-4 roots are walked — those hold class files by
     * definition of the standard.
     */
    private function remotePhp(): string
    {
        return <<<'PHP'
$in = json_decode(base64_decode((string) getenv('DPLY_JC_IN')), true);
if (! is_array($in)) { return; }
$T = function ($cb, $d = null) { try { return $cb(); } catch (\Throwable $e) { return $d; } };
$app = $T(function () {
    require getcwd().'/vendor/autoload.php';
    $a = require getcwd().'/bootstrap/app.php';
    $a->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    return $a;
});
if ($app === null) { echo 'DPLY_JC_START'.json_encode(['error' => 'Could not boot the application.']).'DPLY_JC_END'; return; }
$limit = (int) $in['limit'];
$composer = $T(fn () => json_decode((string) file_get_contents(getcwd().'/composer.json'), true), null);
$roots = $T(fn () => (array) ($composer['autoload']['psr-4'] ?? []), []);
if ($roots === []) { echo 'DPLY_JC_START'.json_encode(['error' => 'This app declares no PSR-4 namespaces, so its classes cannot be enumerated.']).'DPLY_JC_END'; return; }
$candidates = [];
foreach ($roots as $ns => $paths) {
    foreach ((array) $paths as $path) {
        $base = rtrim(getcwd().'/'.trim((string) $path, '/'), '/');
        if (! is_dir($base)) { continue; }
        $it = $T(fn () => new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)), null);
        if ($it === null) { continue; }
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') { continue; }
            $rel = substr($file->getPathname(), strlen($base) + 1);
            $candidates[] = rtrim((string) $ns, '\\').'\\'.str_replace(['/', '.php'], ['\\', ''], $rel);
            if (count($candidates) > 5000) { break 3; }
        }
    }
}
sort($candidates);
$jobs = [];
$truncated = false;
foreach ($candidates as $class) {
    if (count($jobs) >= $limit) { $truncated = true; break; }
    if (! $T(fn () => class_exists($class), false)) { continue; }
    $ref = $T(fn () => new \ReflectionClass($class), null);
    if ($ref === null || ! $ref->isInstantiable()) { continue; }
    if (! $ref->implementsInterface(\Illuminate\Contracts\Queue\ShouldQueue::class)) { continue; }
    $defaults = $ref->getDefaultProperties();
    $ctor = $ref->getConstructor();
    // Required parameters are the whole reason a job cannot be test-dispatched
    // from here: dply has no idea what a valid Order or User is for this app.
    $required = $ctor === null ? 0 : $ctor->getNumberOfRequiredParameters();
    $params = [];
    $scalarArgs = true;
    if ($ctor !== null) {
        foreach ($ctor->getParameters() as $p) {
            $t = $p->getType();
            $params[] = ($t ? $t.' ' : '').'$'.$p->getName();
            // A class-typed required parameter is the wall: dply can pass 42,
            // it cannot pass an Order. Recorded here so the page can say which
            // jobs are askable rather than discovering it at dispatch.
            if (! $p->isOptional() && $t instanceof \ReflectionNamedType && ! $t->isBuiltin()) { $scalarArgs = false; }
        }
    }
    // Kind decides what can be RUN from the page. A broadcast event and a
    // mailable both implement ShouldQueue and neither can be handed to the bus:
    // one needs event(), the other a recipient. Scanning dply itself surfaced
    // broadcast events sitting in the catalogue looking dispatchable.
    $kind = 'job';
    if (is_subclass_of($class, \Illuminate\Mail\Mailable::class)) { $kind = 'mail'; }
    elseif (is_subclass_of($class, \Illuminate\Notifications\Notification::class)) { $kind = 'notification'; }
    elseif ($ref->implementsInterface(\Illuminate\Contracts\Broadcasting\ShouldBroadcast::class)) { $kind = 'broadcast'; }
    elseif (str_contains($class, '\\Listeners\\')) { $kind = 'listener'; }
    $uses = $T(fn () => class_uses_recursive($class), []) ?: [];
    $jobs[] = [
        'class' => $class,
        'kind' => $kind,
        'queue' => is_string($defaults['queue'] ?? null) ? $defaults['queue'] : null,
        'connection' => is_string($defaults['connection'] ?? null) ? $defaults['connection'] : null,
        'tries' => is_int($defaults['tries'] ?? null) ? $defaults['tries'] : null,
        'timeout' => is_int($defaults['timeout'] ?? null) ? $defaults['timeout'] : null,
        'required_args' => $required,
        'scalar_args' => $scalarArgs,
        'signature' => implode(', ', $params),
        'unique' => $ref->implementsInterface(\Illuminate\Contracts\Queue\ShouldBeUnique::class),
        'batchable' => in_array(\Illuminate\Bus\Batchable::class, $uses, true),
    ];
}
echo 'DPLY_JC_START'.json_encode(['jobs' => $jobs, 'truncated' => $truncated]).'DPLY_JC_END';
PHP;
    }

    /**
     * @return array<string, mixed>
     */
    private function extract(string $buffer): array
    {
        if (preg_match('/DPLY_JC_START(.*?)DPLY_JC_END/s', $buffer, $m) !== 1) {
            return ['error' => 'The site did not return a readable job list. Is the app deployed and bootable?'];
        }

        $data = json_decode(trim($m[1]), true);

        return is_array($data) ? $data : ['error' => 'The site returned an unreadable job list.'];
    }
}

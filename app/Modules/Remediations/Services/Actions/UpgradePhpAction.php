<?php

declare(strict_types=1);

namespace App\Modules\Remediations\Services\Actions;

use App\Jobs\ApplySiteWebserverConfigJob;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Remediations\Services\RemediationActionInterface;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\ServerPhpManager;
use App\Support\Sites\PhpVersionUpgradePlanner;
use Throwable;

/**
 * Install the PHP version Composer asked for, make it the CLI default, and
 * switch this site onto it so the next deploy's `composer install` and FPM
 * pool both use the new runtime.
 */
class UpgradePhpAction implements RemediationActionInterface
{
    public function apply(?Server $server, ?Site $site, ?string $userId, ConsoleEmitter $emit): ?string
    {
        if (! $server instanceof Server) {
            return 'This fix needs a server.';
        }

        if (! $site instanceof Site) {
            return 'This fix needs a site, but the error isn’t tied to one.';
        }

        $target = PhpVersionUpgradePlanner::targetForSite($site);
        if ($target === null) {
            $target = PhpVersionUpgradePlanner::catalogVersionFor(
                PhpVersionUpgradePlanner::requiredVersion(null, $site) ?? '8.4',
            );
        }

        if ($target === null) {
            return 'Could not determine which PHP version to install.';
        }

        $php = app(ServerPhpManager::class);

        try {
            $stream = $this->outputStreamer($emit);

            $emit->step('fix', sprintf('Installing PHP %s on %s …', $target, $server->name));
            $php->applyPackageAction(
                $server,
                'install',
                $target,
                $this->progress($emit),
                actingUserId: $userId,
                onOutput: $stream,
            );

            $emit->step('fix', sprintf('Setting PHP %s as the CLI default …', $target));
            $php->applyPackageAction(
                $server,
                'set_cli_default',
                $target,
                $this->progress($emit),
                actingUserId: $userId,
                onOutput: $stream,
            );
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        $site->runtime = 'php';
        $site->runtime_version = $target;
        $site->save();

        ApplySiteWebserverConfigJob::dispatch((string) $site->id, $userId);
        $emit->success('fix', sprintf(
            'PHP %s is installed and this site now uses it. Webserver config is queued. Re-deploy to continue.',
            $target,
        ));

        return null;
    }

    /**
     * Stream apt/PPA lines into the console as they arrive — buffering until
     * `exec()` returns made the upgrade look stuck after "apt install php8.4".
     *
     * @return \Closure(string $line): void
     */
    private function outputStreamer(ConsoleEmitter $emit): \Closure
    {
        return static function (string $line) use ($emit): void {
            $emit($line, 'info', 'php');
        };
    }

    /**
     * @return \Closure(string $step, string $action, string $version): void
     */
    private function progress(ConsoleEmitter $emit): \Closure
    {
        return static function (string $step, string $action, string $version) use ($emit): void {
            $label = match ($step) {
                'sync_inventory' => 'Refreshing PHP inventory',
                'execute' => $action === 'install'
                    ? sprintf('apt install php%s', $version)
                    : sprintf('%s php%s', str_replace('_', ' ', $action), $version),
                default => $step,
            };
            $emit->step('php', $label);
        };
    }
}

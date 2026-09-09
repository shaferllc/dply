<?php

declare(strict_types=1);

namespace App\Modules\Remediations\Services\Actions;

use App\Models\ErrorEvent;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Remediations\Services\RemediationActionInterface;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\ServerPhpManager;
use App\Support\Sites\MissingPhpExtensionResolver;
use Throwable;

/**
 * Install the PHP extension an error is actually asking for.
 *
 * `Class "Imagick" not found` is not an application bug, but it reads as one:
 * PHP names the symbol, never the package, so the site 500s until somebody
 * knows that Imagick means php-imagick. The server already has a full extension
 * manager and catalog behind the PHP tab — this points it at the extension the
 * log names, for the PHP version THIS SITE runs, so a neighbour pinned to
 * another version is untouched.
 *
 * One extension per run, resolved from the log rather than chosen by the
 * operator: an install that names what it installs can be verified, and a fix
 * panel that guesses is worse than one that says it cannot tell.
 */
class InstallPhpExtensionAction implements RemediationActionInterface
{
    public function apply(?Server $server, ?Site $site, ?string $userId, ConsoleEmitter $emit): ?string
    {
        if (! $server instanceof Server) {
            return 'This fix needs a server.';
        }

        if (! $site instanceof Site) {
            return 'This fix needs a site, but the error isn’t tied to one.';
        }

        $extension = MissingPhpExtensionResolver::fromErrorText($this->errorText($site));

        if ($extension === null) {
            return 'Could not tell which PHP extension is missing from the error. Install it from the server’s PHP tab.';
        }

        $version = trim((string) ($site->phpVersion() ?: ''));

        if ($version === '') {
            return 'This site has no PHP version set, so there is no runtime to install the extension into.';
        }

        $php = app(ServerPhpManager::class);

        // The catalog is the boundary: it knows which extensions are installable
        // on which PHP versions. Asking for one it does not carry would fail
        // deep inside the task with a bare apt error.
        if ($php->extensionCatalogEntry($version, $extension) === null) {
            return sprintf('PHP %s on this server has no installable "%s" extension in dply’s catalog.', $version, $extension);
        }

        try {
            $emit->step('fix', sprintf('Installing the %s extension for PHP %s on %s …', $extension, $version, $server->name));

            $php->applyExtensionAction(
                $server,
                'install',
                $version,
                $extension,
                static fn (string $step) => $emit->step('php', $step),
            );
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        $emit->success('fix', sprintf(
            'The %s extension is installing for PHP %s. Once it finishes, retry the request or re-deploy.',
            $extension,
            $version,
        ));

        return null;
    }

    /**
     * The text to resolve the extension from: the site's most recent
     * undismissed error first (this failure surfaces at runtime, in
     * laravel.log, not in a deploy log), falling back to the last deploy.
     */
    private function errorText(Site $site): string
    {
        $event = ErrorEvent::query()
            ->where('site_id', $site->id)
            ->whereNull('dismissed_at')
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->first();

        $parts = [
            (string) ($event?->title ?? ''),
            (string) ($event?->detail ?? ''),
            (string) ($site->latestDeployment()?->log_output ?? ''),
        ];

        return trim(implode("\n", array_filter($parts, static fn (string $p): bool => trim($p) !== '')));
    }
}

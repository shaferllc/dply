<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Cli\CliPackageTarballBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class CliInstallController extends Controller
{
    /**
     * Origin of the request that fetched install.sh / the tarball — not APP_URL.
     * Local APP_URL often points at a host that isn't registered (e.g. dplyi.test)
     * while operators curl a working Valet host (dply.test).
     */
    private function requestOrigin(Request $request): string
    {
        return rtrim($request->root(), '/');
    }

    public function installScript(Request $request): Response
    {
        $path = base_path('packages/dply-cli/install.sh');
        abort_unless(is_readable($path), 404);

        $contents = (string) file_get_contents($path);
        $baseUrl = $this->requestOrigin($request);
        $replacements = [
            '__DPLY_DEFAULT_BASE_URL__' => $baseUrl,
            '__DPLY_CLI_INSTALL_METHOD__' => (string) config('cli.install_method', 'tarball'),
            '__DPLY_CLI_NPM_PUBLISHED__' => config('cli.npm_published', false) ? '1' : '0',
        ];
        $contents = str_replace(array_keys($replacements), array_values($replacements), $contents);

        return response($contents, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'inline; filename="install.sh"',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public function packageVersion(Request $request, CliPackageTarballBuilder $builder): JsonResponse
    {
        $packageJson = base_path('packages/dply-cli/package.json');
        abort_unless(is_readable($packageJson), 404);

        /** @var array{version?: string, name?: string} $meta */
        $meta = json_decode((string) file_get_contents($packageJson), true, 512, JSON_THROW_ON_ERROR);

        $origin = $this->requestOrigin($request);

        return response()->json([
            'name' => $meta['name'] ?? '@dply/cli',
            'version' => $meta['version'] ?? '0.0.0',
            // What `dply update` actually compares. See
            // {@see CliPackageTarballBuilder::buildId()} — package.json's
            // version is hand-maintained and does not move when a command lands.
            'build' => $builder->buildId(),
            'install_url' => $origin.'/cli/install.sh',
            'package_url' => $origin.'/cli/dply-cli.tgz',
        ]);
    }

    public function packageTarball(Request $request, CliPackageTarballBuilder $builder): Response
    {
        try {
            $contents = $builder->cachedContents($this->requestOrigin($request));
        } catch (Throwable $e) {
            report($e);

            abort(500, 'Could not build CLI package archive.');
        }

        return response($contents, 200, [
            'Content-Type' => 'application/gzip',
            'Content-Disposition' => 'attachment; filename="dply-cli.tgz"',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Cli\CliPackageTarballBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Throwable;

class CliInstallController extends Controller
{
    public function installScript(): Response
    {
        $path = base_path('packages/dply-cli/install.sh');
        abort_unless(is_readable($path), 404);

        $contents = (string) file_get_contents($path);
        $baseUrl = rtrim((string) config('app.url'), '/');
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

    public function packageVersion(): JsonResponse
    {
        $packageJson = base_path('packages/dply-cli/package.json');
        abort_unless(is_readable($packageJson), 404);

        /** @var array{version?: string, name?: string} $meta */
        $meta = json_decode((string) file_get_contents($packageJson), true, 512, JSON_THROW_ON_ERROR);

        return response()->json([
            'name' => $meta['name'] ?? '@dply/cli',
            'version' => $meta['version'] ?? '0.0.0',
            'install_url' => url('/cli/install.sh'),
            'package_url' => url('/cli/dply-cli.tgz'),
        ]);
    }

    public function packageTarball(CliPackageTarballBuilder $builder): Response
    {
        try {
            $contents = $builder->cachedContents();
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

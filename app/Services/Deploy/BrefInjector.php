<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Auto-injects Bref into a checked-out PHP/Laravel app so it can run on AWS
 * Lambda — the user pushes a plain repo, dply adds the runtime glue at build
 * time. No serverless boilerplate in the user's repo.
 *
 * Bref itself stays an upstream dependency (added to the app's composer
 * requirements here); the dply-side resolution of Lambda layers + function
 * config lives in the shaferllc/dply-php-lambda bridge package.
 *
 * Two-step shape: {@see plan()} is pure (reads composer.json, decides what's
 * needed) and fully testable; {@see inject()} performs the side effect
 * (composer require inside the working tree).
 */
class BrefInjector
{
    /**
     * Inspect a checked-out app and decide what Bref injection it needs.
     * Pure — reads composer.json, runs nothing.
     *
     * @return array{php: bool, framework: string, packages: list<string>, handler: string, web: bool}
     */
    /** @return array<string, mixed> */
    public function plan(string $workingDirectory): array
    {
        $composerPath = rtrim($workingDirectory, '/').'/composer.json';

        $notPhp = ['php' => false, 'framework' => 'unknown', 'packages' => [], 'handler' => '', 'web' => true];

        if (! is_file($composerPath)) {
            return $notPhp;
        }

        $composer = json_decode((string) file_get_contents($composerPath), true);
        if (! is_array($composer)) {
            return $notPhp;
        }

        $require = is_array($composer['require'] ?? null) ? $composer['require'] : [];
        $isLaravel = array_key_exists('laravel/framework', $require);

        // bref/bref is the base runtime; Laravel additionally needs the
        // bridge that maps Lambda events onto the Laravel kernel.
        $wanted = ['bref/bref'];
        if ($isLaravel) {
            $wanted[] = 'bref/laravel-bridge';
        }

        // Don't re-require what the app already declares.
        $packages = array_values(array_filter(
            $wanted,
            fn (string $pkg) => ! array_key_exists($pkg, $require),
        ));

        return [
            'php' => true,
            'framework' => $isLaravel ? 'laravel' : 'php',
            // FPM web front controller — Laravel and most PHP web apps.
            'handler' => 'public/index.php',
            'web' => true,
            'packages' => $packages,
        ];
    }

    /**
     * Inject Bref into the checked-out app — composer-requires any missing
     * Bref packages so the built artifact carries the Lambda runtime glue.
     *
     * @return array{php: bool, framework: string, packages: list<string>, handler: string, web: bool, ran: bool, output: string}
     */
    /** @return array<string, mixed> */
    public function inject(string $workingDirectory): array
    {
        $plan = $this->plan($workingDirectory);

        if (! $plan['php'] || $plan['packages'] === []) {
            return $plan + ['ran' => false, 'output' => ''];
        }

        $process = new Process(
            ['composer', 'require', '--no-interaction', '--no-scripts', '--no-audit', ...$plan['packages']],
            $workingDirectory,
        );
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'Bref injection failed: '.trim($process->getErrorOutput()."\n".$process->getOutput())
            );
        }

        return $plan + [
            'ran' => true,
            'output' => trim('Injected Bref: '.implode(', ', $plan['packages'])."\n".$process->getOutput()),
        ];
    }
}

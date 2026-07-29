<?php

declare(strict_types=1);

namespace App\Support\SelfManage;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Idempotently ensures `DPLY_ROOT="…"` is present in supervisord's
 * `[supervisord] environment=` so templates using `%(ENV_DPLY_ROOT)s` resolve.
 */
final class EnsuresSupervisordDplyRoot
{
    /**
     * @return array{ok: bool, changed: bool, message: string}
     */
    public function ensure(string $dplyRoot, ?string $supervisordConf = null, bool $dryRun = false): array
    {
        $dplyRoot = rtrim($dplyRoot, '/');
        if ($dplyRoot === '' || ! is_dir($dplyRoot)) {
            return ['ok' => false, 'changed' => false, 'message' => 'DPLY_ROOT path is missing: '.$dplyRoot];
        }

        $confPath = $supervisordConf ?: (string) config(
            'self_manage.supervisor.supervisord_conf',
            '/etc/supervisor/supervisord.conf',
        );

        if (! is_file($confPath) || ! is_readable($confPath)) {
            return ['ok' => false, 'changed' => false, 'message' => 'supervisord.conf not readable: '.$confPath];
        }

        $raw = (string) file_get_contents($confPath);
        $patched = $this->patchEnvironment($raw, $dplyRoot);
        if ($patched === $raw) {
            return ['ok' => true, 'changed' => false, 'message' => 'DPLY_ROOT already set to '.$dplyRoot];
        }

        if ($dryRun) {
            return ['ok' => true, 'changed' => true, 'message' => 'Would update DPLY_ROOT in '.$confPath];
        }

        $this->writePrivileged($confPath, $patched);

        return ['ok' => true, 'changed' => true, 'message' => 'Updated DPLY_ROOT in '.$confPath];
    }

    public function patchEnvironment(string $conf, string $dplyRoot): string
    {
        $escaped = addcslashes($dplyRoot, '\\"');
        $assignment = 'DPLY_ROOT="'.$escaped.'"';

        if (preg_match('/^\[supervisord\][^\[]*/ms', $conf, $blockMatch, PREG_OFFSET_CAPTURE) !== 1) {
            return $conf."\n[supervisord]\nenvironment=".$assignment."\n";
        }

        $block = $blockMatch[0][0];
        $offset = (int) $blockMatch[0][1];

        if (preg_match('/^\s*environment\s*=\s*(.+)$/mi', $block, $envMatch) === 1) {
            $existing = trim($envMatch[1]);
            $merged = $this->mergeEnvironmentValue($existing, $assignment);
            if ($merged === $existing) {
                return $conf;
            }
            $newBlock = preg_replace(
                '/^\s*environment\s*=\s*.+$/mi',
                'environment='.$merged,
                $block,
                1,
            );
        } else {
            $newBlock = rtrim($block)."\nenvironment=".$assignment."\n";
        }

        return substr($conf, 0, $offset).$newBlock.substr($conf, $offset + strlen($block));
    }

    private function mergeEnvironmentValue(string $existing, string $assignment): string
    {
        // Strip prior DPLY_ROOT=… (quoted or bare).
        $without = preg_replace('/(?:^|,)\s*DPLY_ROOT\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s,]+)\s*/i', ',', $existing) ?? $existing;
        $without = trim($without, " \t,");
        if ($without === '') {
            return $assignment;
        }

        return $without.','.$assignment;
    }

    private function writePrivileged(string $path, string $contents): void
    {
        if (is_writable($path)) {
            if (file_put_contents($path, $contents) === false) {
                throw new RuntimeException('Failed to write '.$path);
            }

            return;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'dply-supervisord-');
        if ($tmp === false) {
            throw new RuntimeException('Could not create temp file for supervisord.conf');
        }
        file_put_contents($tmp, $contents);
        $result = Process::timeout(30)->run(['sudo', '-n', 'cp', $tmp, $path]);
        @unlink($tmp);
        if (! $result->successful()) {
            throw new RuntimeException(
                'Failed to write '.$path.' (need passwordless sudo): '.trim($result->errorOutput() ?: $result->output()),
            );
        }
    }
}

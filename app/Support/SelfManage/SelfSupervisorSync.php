<?php

declare(strict_types=1);

namespace App\Support\SelfManage;

use App\Support\DplyRuntime;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Merge-syncs control-plane supervisor templates from dply.yaml (or
 * config/self_manage.php fallback) into an owned conf.d file without
 * clobbering local-only programs or sibling conf files.
 */
class SelfSupervisorSync
{
    public function __construct(
        private EnsuresSupervisordDplyRoot $dplyRoot,
    ) {}

    /**
     * @return array{
     *   ok: bool,
     *   skipped: bool,
     *   role: string,
     *   source: ?string,
     *   dest: ?string,
     *   managed: list<string>,
     *   preserved: list<string>,
     *   collisions: array<string, string>,
     *   message: string,
     *   changed: bool
     * }
     */
    public function sync(
        ?string $roleOverride = null,
        bool $dryRun = false,
        bool $adoptCollisions = false,
        bool $force = false,
    ): array {
        $empty = [
            'ok' => true,
            'skipped' => true,
            'role' => '',
            'source' => null,
            'dest' => null,
            'managed' => [],
            'preserved' => [],
            'collisions' => [],
            'message' => '',
            'changed' => false,
        ];

        $config = $this->loadSupervisorConfig();
        $useTemplates = (bool) $config['use_templates'];
        if (! $useTemplates && ! $force) {
            return $empty + ['message' => 'Template sync disabled (dply.yaml supervisor.use_templates / DPLY_SELF_SUPERVISOR_TEMPLATES=false).'];
        }

        $mode = DplyRuntime::mode();
        if (! $force && ! in_array($mode, [DplyRuntime::MODE_WEB, DplyRuntime::MODE_WORKER], true)) {
            return $empty + ['message' => 'DPLY_RUNTIME is not web/worker — skipping supervisor template sync.'];
        }

        $role = $roleOverride !== null && $roleOverride !== ''
            ? $roleOverride
            : $this->resolveRoleKey($mode);

        $relative = $config['roles'][$role] ?? null;
        if (! is_string($relative) || $relative === '') {
            return $empty + [
                'ok' => false,
                'skipped' => false,
                'role' => $role,
                'message' => "No supervisor template mapped for role `{$role}`.",
            ];
        }

        $source = base_path($relative);
        if (! is_file($source)) {
            return $empty + [
                'ok' => false,
                'skipped' => false,
                'role' => $role,
                'source' => $source,
                'message' => "Template missing: {$source}",
            ];
        }

        $confD = rtrim((string) $config['conf_d'], '/');
        $installAs = (string) $config['install_as'];
        $dest = $confD.'/'.$installAs;

        $template = (string) file_get_contents($source);
        $templateParsed = SupervisorIniSections::parse($template);
        $managedSections = $templateParsed['sections'];
        $managedPrograms = SupervisorIniSections::programNames($managedSections);

        $existingRaw = is_file($dest) && is_readable($dest) ? (string) file_get_contents($dest) : '';
        $existingParsed = $existingRaw !== ''
            ? SupervisorIniSections::parse($existingRaw)
            : ['preamble' => '', 'sections' => []];

        $preserved = [];
        $mergedSections = [];
        foreach ($existingParsed['sections'] as $key => $body) {
            if (isset($managedSections[$key])) {
                continue;
            }
            $mergedSections[$key] = $body;
            if (str_starts_with($key, 'program:')) {
                $preserved[] = substr($key, strlen('program:'));
            }
        }
        foreach ($managedSections as $key => $body) {
            $mergedSections[$key] = $body;
        }

        $preamble = $templateParsed['preamble'] !== ''
            ? $templateParsed['preamble']
            : (string) $existingParsed['preamble'];
        if (! str_contains($preamble, 'Managed by dply:self:sync-supervisor')) {
            $preamble = trim($preamble."\n; Managed by dply:self:sync-supervisor (role {$role})");
        }

        $rendered = SupervisorIniSections::render($preamble, $mergedSections);
        $collisions = $this->findCollisions($confD, $installAs, $managedPrograms);

        if ($collisions !== [] && ! $adoptCollisions) {
            $list = collect($collisions)->map(fn (string $file, string $name) => "{$name} in {$file}")->implode('; ');

            return [
                'ok' => false,
                'skipped' => false,
                'role' => $role,
                'source' => $source,
                'dest' => $dest,
                'managed' => $managedPrograms,
                'preserved' => $preserved,
                'collisions' => $collisions,
                'message' => 'Managed program name(s) already defined elsewhere: '.$list.'. Re-run with --adopt-collisions to strip them from sibling files.',
                'changed' => false,
            ];
        }

        if ($dryRun) {
            return [
                'ok' => true,
                'skipped' => false,
                'role' => $role,
                'source' => $source,
                'dest' => $dest,
                'managed' => $managedPrograms,
                'preserved' => $preserved,
                'collisions' => $collisions,
                'message' => 'Dry-run: would write '.$dest.' ('.count($managedPrograms).' managed, '.count($preserved).' preserved).',
                'changed' => $rendered !== $existingRaw,
            ];
        }

        if ($adoptCollisions && $collisions !== []) {
            $this->stripCollisionsFromSiblings($confD, $installAs, $managedPrograms);
        }

        $rootResult = $this->dplyRoot->ensure(base_path(), dryRun: false);
        $changed = $rendered !== $existingRaw;
        if ($changed) {
            $this->writePrivileged($dest, $rendered);
        }

        $reload = $this->supervisorctlReload();
        $messages = array_filter([
            $changed ? "Wrote {$dest}" : "{$dest} unchanged",
            $rootResult['message'],
            $reload['message'],
        ]);

        return [
            'ok' => $reload['ok'],
            'skipped' => false,
            'role' => $role,
            'source' => $source,
            'dest' => $dest,
            'managed' => $managedPrograms,
            'preserved' => $preserved,
            'collisions' => $collisions,
            'message' => implode(' · ', $messages),
            'changed' => $changed || $rootResult['changed'],
        ];
    }

    public function resolveRoleKey(?string $mode = null): string
    {
        $mode ??= DplyRuntime::mode();
        if ($mode === DplyRuntime::MODE_WEB) {
            return 'web';
        }

        $workerRole = DplyRuntime::workerRole();

        return $workerRole === DplyRuntime::WORKER_ROLE_PRIMARY
            ? 'worker.primary'
            : 'worker.replica';
    }

    /**
     * @return array{use_templates: bool, conf_d: string, install_as: string, roles: array<string, string>}
     */
    public function loadSupervisorConfig(): array
    {
        $fallback = [
            'use_templates' => (bool) config('self_manage.supervisor.use_templates', true),
            'conf_d' => (string) config('self_manage.supervisor.conf_d', '/etc/supervisor/conf.d'),
            'install_as' => (string) config('self_manage.supervisor.install_as', 'dply-platform.conf'),
            'roles' => (array) config('self_manage.supervisor.roles', []),
        ];

        $yamlPath = base_path('dply.yaml');
        if (! is_file($yamlPath) || ! is_readable($yamlPath)) {
            return $fallback;
        }

        try {
            $data = Yaml::parseFile($yamlPath);
        } catch (\Throwable) {
            return $fallback;
        }

        if (! is_array($data) || ! is_array($data['supervisor'] ?? null)) {
            return $fallback;
        }

        $supervisor = $data['supervisor'];
        $roles = is_array($supervisor['roles'] ?? null) ? $supervisor['roles'] : $fallback['roles'];
        $roles = array_filter(
            $roles,
            static fn ($v) => is_string($v) && $v !== '',
        );

        $useTemplates = $fallback['use_templates'];
        if (array_key_exists('use_templates', $supervisor)) {
            $useTemplates = filter_var($supervisor['use_templates'], FILTER_VALIDATE_BOOLEAN);
        }

        return [
            'use_templates' => $useTemplates,
            'conf_d' => is_string($supervisor['conf_d'] ?? null) && $supervisor['conf_d'] !== ''
                ? $supervisor['conf_d']
                : $fallback['conf_d'],
            'install_as' => is_string($supervisor['install_as'] ?? null) && $supervisor['install_as'] !== ''
                ? $supervisor['install_as']
                : $fallback['install_as'],
            'roles' => $roles !== [] ? $roles : $fallback['roles'],
        ];
    }

    /**
     * @param  list<string>  $managedPrograms
     * @return array<string, string> program name => file path
     */
    private function findCollisions(string $confD, string $installAs, array $managedPrograms): array
    {
        if (! is_dir($confD) || $managedPrograms === []) {
            return [];
        }

        $owned = $confD.'/'.$installAs;
        $collisions = [];
        foreach (File::files($confD) as $file) {
            $path = $file->getPathname();
            if ($path === $owned || ! str_ends_with($path, '.conf')) {
                continue;
            }
            $parsed = SupervisorIniSections::parse((string) file_get_contents($path));
            foreach (SupervisorIniSections::programNames($parsed['sections']) as $name) {
                if (in_array($name, $managedPrograms, true)) {
                    $collisions[$name] = $path;
                }
            }
        }

        return $collisions;
    }

    /**
     * @param  list<string>  $managedPrograms
     */
    private function stripCollisionsFromSiblings(string $confD, string $installAs, array $managedPrograms): void
    {
        $owned = $confD.'/'.$installAs;
        foreach (File::files($confD) as $file) {
            $path = $file->getPathname();
            if ($path === $owned || ! str_ends_with($path, '.conf')) {
                continue;
            }
            $raw = (string) file_get_contents($path);
            $parsed = SupervisorIniSections::parse($raw);
            $changed = false;
            foreach ($managedPrograms as $name) {
                $key = 'program:'.$name;
                if (isset($parsed['sections'][$key])) {
                    unset($parsed['sections'][$key]);
                    $changed = true;
                }
            }
            if (! $changed) {
                continue;
            }
            if ($parsed['sections'] === [] && trim($parsed['preamble']) === '') {
                $this->removePrivileged($path);

                continue;
            }
            $this->writePrivileged($path, SupervisorIniSections::render($parsed['preamble'], $parsed['sections']));
        }
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function supervisorctlReload(): array
    {
        $reread = Process::timeout(60)->run(['sudo', '-n', 'supervisorctl', 'reread']);
        if (! $reread->successful()) {
            $reread = Process::timeout(60)->run(['supervisorctl', 'reread']);
        }
        if (! $reread->successful()) {
            return [
                'ok' => false,
                'message' => 'supervisorctl reread failed: '.trim($reread->errorOutput() ?: $reread->output()),
            ];
        }

        $update = Process::timeout(60)->run(['sudo', '-n', 'supervisorctl', 'update']);
        if (! $update->successful()) {
            $update = Process::timeout(60)->run(['supervisorctl', 'update']);
        }
        if (! $update->successful()) {
            return [
                'ok' => false,
                'message' => 'supervisorctl update failed: '.trim($update->errorOutput() ?: $update->output()),
            ];
        }

        return ['ok' => true, 'message' => 'supervisorctl reread + update ok'];
    }

    private function writePrivileged(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            throw new RuntimeException('Supervisor conf.d missing: '.$dir);
        }

        if (is_writable($dir) && (! is_file($path) || is_writable($path))) {
            File::ensureDirectoryExists($dir);
            if (file_put_contents($path, $contents) === false) {
                throw new RuntimeException('Failed to write '.$path);
            }

            return;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'dply-sv-');
        if ($tmp === false) {
            throw new RuntimeException('Could not create temp file');
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

    private function removePrivileged(string $path): void
    {
        if (is_writable(dirname($path))) {
            @unlink($path);

            return;
        }
        Process::timeout(30)->run(['sudo', '-n', 'rm', '-f', $path]);
    }
}

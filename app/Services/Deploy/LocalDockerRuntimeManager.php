<?php

namespace App\Services\Deploy;

use App\Models\Site;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class LocalDockerRuntimeManager
{
    public function __construct(
        private readonly LocalRuntimeWorkspace $workspace,
        private readonly DockerRuntimeDockerfileBuilder $dockerfileBuilder,
        private readonly DockerComposeArtifactBuilder $composeBuilder,
    ) {}

    /**
     * @return array{output: string, sha: ?string, status: string, logs: array<int, string>, compose_yaml: string, dockerfile: string, workspace_path: string, repository_checkout_path: string, working_directory: string, generated_compose_path: string, generated_dockerfile_path: string, publication?: array<string, mixed>, runtime_details?: array<string, mixed>}
     */
    public function deploy(Site $site): array
    {
        $workspace = $this->workspace->ensure($site);
        $repositoryPath = $workspace['repository_path'];
        $workingDirectory = $workspace['working_directory'] ?? $repositoryPath;
        $dockerfile = $this->dockerfileBuilder->build($site);
        $composeYaml = $this->composeBuilder->build($site);

        $dockerfilePath = $workingDirectory.'/Dockerfile.dply';
        $composePath = $workingDirectory.'/docker-compose.dply.yml';

        File::put($dockerfilePath, $dockerfile);
        File::put($composePath, $composeYaml);

        try {
            $output = $this->run(
                ['docker', 'compose', '-f', $composePath, 'up', '-d', '--build'],
                $workingDirectory,
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException($this->buildFailureMessage($composePath, $workingDirectory, $e), previous: $e);
        }

        $status = $this->run(
            ['docker', 'compose', '-f', $composePath, 'ps', '--all'],
            $workingDirectory,
            allowFailure: true,
        );
        $runtimeDetails = $this->collectRuntimeDetails($composePath, $workingDirectory);

        return [
            'output' => trim(implode("\n\n", array_filter([
                'Local Docker deploy completed.',
                $output,
                $status,
            ]))),
            'sha' => $workspace['revision'],
            'status' => 'running',
            'logs' => [$status],
            'compose_yaml' => $composeYaml,
            'dockerfile' => $dockerfile,
            'workspace_path' => $workspace['workspace_path'],
            'repository_checkout_path' => $repositoryPath,
            'working_directory' => $workingDirectory,
            'generated_compose_path' => $composePath,
            'generated_dockerfile_path' => $dockerfilePath,
            'publication' => $this->publicationFromRuntimeDetails($runtimeDetails),
            'runtime_details' => $runtimeDetails,
        ];
    }

    /**
     * @return array{status: string, output: string, publication?: array<string, mixed>, runtime_details?: array<string, mixed>}
     */
    public function start(Site $site): array
    {
        return $this->simpleAction($site, ['up', '-d'], 'running', 'Local containers started.', refreshRuntimeDetails: true);
    }

    /**
     * @return array{status: string, output: string, publication?: array<string, mixed>, runtime_details?: array<string, mixed>}
     */
    public function stop(Site $site): array
    {
        return $this->simpleAction($site, ['stop'], 'stopped', 'Local containers stopped.', refreshRuntimeDetails: true);
    }

    /**
     * @return array{status: string, output: string, publication?: array<string, mixed>, runtime_details?: array<string, mixed>}
     */
    public function restart(Site $site): array
    {
        return $this->simpleAction($site, ['restart'], 'running', 'Local containers restarted.', refreshRuntimeDetails: true);
    }

    /**
     * @return array{status: string, output: string}
     */
    public function destroy(Site $site): array
    {
        return $this->simpleAction($site, ['down', '--remove-orphans', '--volumes'], 'destroyed', 'Local containers destroyed.');
    }

    /**
     * @return array{status: string, output: string, publication?: array<string, mixed>, runtime_details?: array<string, mixed>}
     */
    public function status(Site $site): array
    {
        return $this->simpleAction($site, ['ps', '--all'], 'unknown', 'Local runtime status refreshed.', allowFailure: true, refreshRuntimeDetails: true);
    }

    /**
     * @return array{status: string, output: string, publication?: array<string, mixed>, runtime_details?: array<string, mixed>}
     */
    public function inspect(Site $site): array
    {
        return $this->simpleAction($site, ['ps', '--all'], 'unknown', 'Docker details refreshed.', allowFailure: true, refreshRuntimeDetails: true);
    }

    /**
     * @return array{status: string, output: string}
     */
    public function logs(Site $site): array
    {
        return $this->simpleAction($site, ['logs', '--tail', '200', '--no-color'], 'unknown', 'Local runtime logs refreshed.', allowFailure: true);
    }

    /**
     * @return array{status: string, output: string, publication?: array<string, mixed>, runtime_details?: array<string, mixed>}
     */
    public function errors(Site $site): array
    {
        $runtime = is_array($site->meta['docker_runtime'] ?? null) ? $site->meta['docker_runtime'] : [];
        $repositoryPath = (string) ($runtime['repository_checkout_path'] ?? '');
        $workingDirectory = (string) ($runtime['working_directory'] ?? $repositoryPath);
        $composePath = (string) ($runtime['generated_compose_path'] ?? '');

        if ($workingDirectory === '' || $composePath === '') {
            throw new \RuntimeException($this->missingRuntimeMessage($site, ['logs'], $repositoryPath, $workingDirectory, $composePath));
        }

        $runtimeDetails = $this->collectRuntimeDetails($composePath, $workingDirectory);
        $sections = array_filter([
            $this->inspectCompose(
                ['docker', 'compose', '-f', $composePath, 'ps', '--all'],
                $workingDirectory,
                'docker compose ps --all',
            ),
            $this->inspectCompose(
                ['docker', 'compose', '-f', $composePath, 'logs', '--tail', '200', '--no-color'],
                $workingDirectory,
                'docker compose logs --tail 200',
            ),
            $this->applicationErrorLogs($site, $workingDirectory, $runtimeDetails),
        ]);

        return [
            'status' => 'error_diagnostics',
            'output' => trim(implode("\n\n", array_merge([
                'Runtime error diagnostics refreshed.',
            ], $sections))),
            'runtime_details' => $runtimeDetails,
            'publication' => $this->publicationFromRuntimeDetails($runtimeDetails),
        ];
    }

    /**
     * @param  list<string>  $subCommand
     * @return array{status: string, output: string, publication?: array<string, mixed>, runtime_details?: array<string, mixed>}
     */
    private function simpleAction(
        Site $site,
        array $subCommand,
        string $status,
        string $prefix,
        bool $allowFailure = false,
        bool $refreshRuntimeDetails = false,
    ): array {
        $runtime = is_array($site->meta['docker_runtime'] ?? null) ? $site->meta['docker_runtime'] : [];
        $repositoryPath = (string) ($runtime['repository_checkout_path'] ?? '');
        $workingDirectory = (string) ($runtime['working_directory'] ?? $repositoryPath);
        $composePath = (string) ($runtime['generated_compose_path'] ?? '');

        if ($workingDirectory === '' || $composePath === '') {
            throw new \RuntimeException($this->missingRuntimeMessage($site, $subCommand, $repositoryPath, $workingDirectory, $composePath));
        }

        $output = $this->run(['docker', 'compose', '-f', $composePath, ...$subCommand], $workingDirectory, allowFailure: $allowFailure);

        $result = [
            'status' => $status,
            'output' => trim($prefix."\n\n".$output),
        ];

        if (! $refreshRuntimeDetails) {
            return $result;
        }

        $runtimeDetails = $this->collectRuntimeDetails($composePath, $workingDirectory);

        $result['runtime_details'] = $runtimeDetails;
        $result['publication'] = $this->publicationFromRuntimeDetails($runtimeDetails);

        return $result;
    }

    private function run(array $command, string $workingDirectory, bool $allowFailure = false): string
    {
        $process = new Process($command, $workingDirectory);
        $process->setTimeout((int) config('sites.local_runtime_docker_timeout_seconds', 1800));

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new \RuntimeException($this->timedOutMessage($process, $command), previous: $e);
        }

        $output = trim($process->getOutput().($process->getErrorOutput() !== '' ? "\n".$process->getErrorOutput() : ''));

        if (! $allowFailure && ! $process->isSuccessful()) {
            throw new \RuntimeException($this->commandFailureMessage($command, $workingDirectory, $output, $process->getExitCode()));
        }

        return $output;
    }

    private function buildFailureMessage(string $composePath, string $workingDirectory, \Throwable $exception): string
    {
        $sections = [
            $exception->getMessage(),
            $this->runtimeDiagnostics($workingDirectory, $composePath),
        ];

        $status = $this->inspectCompose(
            ['docker', 'compose', '-f', $composePath, 'ps', '--all'],
            $workingDirectory,
            'docker compose ps --all',
        );

        if ($status !== '') {
            $sections[] = $status;
        }

        $logs = $this->inspectCompose(
            ['docker', 'compose', '-f', $composePath, 'logs', '--tail', '200', '--no-color'],
            $workingDirectory,
            'docker compose logs --tail 200',
        );

        if ($logs !== '') {
            $sections[] = $logs;
        }

        return trim(implode("\n\n", array_filter($sections)));
    }

    /**
     * @param  list<string>  $subCommand
     */
    private function missingRuntimeMessage(Site $site, array $subCommand, string $repositoryPath, string $workingDirectory, string $composePath): string
    {
        return trim(implode("\n\n", array_filter([
            'This Docker runtime has not been deployed yet.',
            'Requested command: '.implode(' ', ['docker', 'compose', '-f', $composePath !== '' ? $composePath : '<missing-compose-path>', ...$subCommand]),
            $this->runtimeDiagnostics($workingDirectory !== '' ? $workingDirectory : $repositoryPath, $composePath, $site),
        ])));
    }

    /**
     * @param  list<string>  $command
     */
    private function commandFailureMessage(array $command, string $workingDirectory, string $output, ?int $exitCode): string
    {
        $commandString = implode(' ', array_map('escapeshellarg', $command));

        return trim(implode("\n\n", array_filter([
            $output !== '' ? $output : 'Docker command failed.',
            'Exit code: '.($exitCode ?? 'unknown'),
            'Working directory: '.$workingDirectory,
            'Command: '.$commandString,
            $this->runtimeDiagnostics($workingDirectory, $this->composePathFromCommand($command)),
        ])));
    }

    private function composePathFromCommand(array $command): string
    {
        $index = array_search('-f', $command, true);

        return is_int($index) && isset($command[$index + 1]) ? (string) $command[$index + 1] : '';
    }

    private function runtimeDiagnostics(string $workingDirectory, string $composePath, ?Site $site = null): string
    {
        $lines = [
            '--- runtime diagnostics ---',
            'working_directory: '.($workingDirectory !== '' ? $workingDirectory : '<missing>'),
            'working_directory_exists: '.(File::isDirectory($workingDirectory) ? 'true' : 'false'),
            'compose_path: '.($composePath !== '' ? $composePath : '<missing>'),
            'compose_file_exists: '.($composePath !== '' && File::exists($composePath) ? 'true' : 'false'),
            'docker_bin: '.trim((string) shell_exec('command -v docker 2>/dev/null')),
        ];

        $dockerVersion = trim((string) shell_exec('docker --version 2>&1'));
        if ($dockerVersion !== '') {
            $lines[] = 'docker_version: '.$dockerVersion;
        }

        if ($site) {
            $lines[] = 'site_id: '.(string) $site->getKey();
            $lines[] = 'runtime_target_family: '.$site->runtimeTargetFamily();
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{compose_ps_json: ?string, containers: list<array<string, mixed>>, collected_at: string}
     */
    public function collectRuntimeDetailsForSite(Site $site): array
    {
        $runtime = is_array($site->meta['docker_runtime'] ?? null) ? $site->meta['docker_runtime'] : [];
        $repositoryPath = (string) ($runtime['repository_checkout_path'] ?? '');
        $workingDirectory = (string) ($runtime['working_directory'] ?? $repositoryPath);
        $composePath = (string) ($runtime['generated_compose_path'] ?? '');

        if ($workingDirectory === '' || $composePath === '') {
            throw new \RuntimeException(__('This Docker runtime has not been deployed yet.'));
        }

        return $this->collectRuntimeDetails($composePath, $workingDirectory);
    }

    /**
     * @return array<string, mixed>
     */
    private function collectRuntimeDetails(string $composePath, string $workingDirectory): array
    {
        $psJson = trim($this->run(
            ['docker', 'compose', '-f', $composePath, 'ps', '--format', 'json'],
            $workingDirectory,
            allowFailure: true,
        ));

        $containers = [];
        foreach ($this->decodeComposePsJson($psJson) as $containerSummary) {
            $containerId = (string) ($containerSummary['ID'] ?? $containerSummary['Id'] ?? '');
            if ($containerId === '') {
                continue;
            }

            $inspectJson = trim($this->run(
                ['docker', 'inspect', $containerId],
                $workingDirectory,
                allowFailure: true,
            ));

            $containers[] = $this->normalizeContainerDetails(
                $containerSummary,
                $this->decodeInspectJson($inspectJson)
            );
        }

        return [
            'compose_ps_json' => $psJson !== '' ? $psJson : null,
            'containers' => array_values(array_filter($containers)),
            'collected_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeComposePsJson(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (is_array($decoded) && array_is_list($decoded)) {
            return array_values(array_filter($decoded, 'is_array'));
        }

        if (is_array($decoded)) {
            return [$decoded];
        }

        $wrapped = json_decode('['.$json.']', true);

        return is_array($wrapped) && array_is_list($wrapped)
            ? array_values(array_filter($wrapped, 'is_array'))
            : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeInspectJson(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (is_array($decoded) && array_is_list($decoded)) {
            return is_array($decoded[0] ?? null) ? $decoded[0] : [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $inspect
     * @return array<string, mixed>
     */
    private function normalizeContainerDetails(array $summary, array $inspect): array
    {
        $networks = is_array(data_get($inspect, 'NetworkSettings.Networks')) ? data_get($inspect, 'NetworkSettings.Networks') : [];
        $primaryNetwork = [];
        if ($networks !== []) {
            $firstNetwork = reset($networks);
            $primaryNetwork = is_array($firstNetwork) ? $firstNetwork : [];
        }

        $name = ltrim((string) (data_get($inspect, 'Name') ?: data_get($summary, 'Name') ?: ''), '/');
        $hostname = (string) (data_get($inspect, 'Config.Hostname') ?: '');
        $service = (string) (data_get($summary, 'Service') ?: data_get($inspect, 'Config.Labels.com.docker.compose.service') ?: '');
        $ipv4 = (string) (data_get($primaryNetwork, 'IPAddress') ?: data_get($inspect, 'NetworkSettings.IPAddress') ?: '');
        $orbHostname = $this->possibleOrbHostname($name, $hostname);

        return array_filter([
            'id' => (string) (data_get($inspect, 'Id') ?: data_get($summary, 'ID') ?: data_get($summary, 'Id') ?: ''),
            'name' => $name,
            'service' => $service,
            'state' => (string) (data_get($summary, 'State') ?: data_get($inspect, 'State.Status') ?: ''),
            'health' => (string) (data_get($summary, 'Health') ?: data_get($inspect, 'State.Health.Status') ?: ''),
            'hostname' => $hostname,
            'orb_hostname' => $orbHostname,
            'ipv4' => $ipv4,
            'network_name' => is_string(key($networks)) ? (string) key($networks) : null,
            'ports' => data_get($summary, 'Publishers'),
            'raw_summary' => $summary !== [] ? $summary : null,
        ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    private function possibleOrbHostname(string $name, string $hostname): ?string
    {
        foreach ([$name, $hostname] as $candidate) {
            $normalized = trim($candidate);
            if ($normalized === '') {
                continue;
            }

            $normalized = str_replace('_', '.', $normalized);

            return $normalized.'.orb.local';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $runtimeDetails
     * @return array<string, mixed>
     */
    private function publicationFromRuntimeDetails(array $runtimeDetails): array
    {
        $container = collect($runtimeDetails['containers'] ?? [])->first(fn (mixed $entry): bool => is_array($entry));
        if (! is_array($container)) {
            return [];
        }

        $hostname = (string) ($container['orb_hostname'] ?? $container['hostname'] ?? '');
        $ipv4 = (string) ($container['ipv4'] ?? '');

        return array_filter([
            'hostname' => $hostname !== '' ? $hostname : null,
            'url' => $hostname !== '' ? 'http://'.$hostname : null,
            'container_ip' => $ipv4 !== '' ? $ipv4 : null,
            'container_name' => $container['name'] ?? null,
            'docker_service' => $container['service'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $runtimeDetails
     */
    private function applicationErrorLogs(Site $site, string $workingDirectory, array $runtimeDetails): string
    {
        if ((string) data_get($site->meta, 'docker_runtime.detected.framework') !== 'laravel') {
            return '';
        }

        $container = collect($runtimeDetails['containers'] ?? [])->first(fn (mixed $entry): bool => is_array($entry) && filled($entry['name'] ?? null));
        if (! is_array($container)) {
            return '';
        }

        $containerName = (string) ($container['name'] ?? '');
        if ($containerName === '') {
            return '';
        }

        try {
            $output = $this->run([
                'docker',
                'exec',
                $containerName,
                'sh',
                '-lc',
                'test -f /var/www/html/storage/logs/laravel.log && tail -n 200 /var/www/html/storage/logs/laravel.log || echo "Laravel log not found."',
            ], $workingDirectory, allowFailure: true);

            return $output !== '' ? "--- laravel.log tail ---\n".$output : '';
        } catch (\Throwable $e) {
            return "--- laravel.log tail ---\n".$e->getMessage();
        }
    }

    /**
     * Run a shell command inside the first app container (same resolution as runtime diagnostics).
     *
     * @param  callable(string): void  $onChunk
     */
    public function execInPrimaryContainer(Site $site, string $shellCommand, int $timeoutSeconds, callable $onChunk): int
    {
        $runtime = is_array($site->meta['docker_runtime'] ?? null) ? $site->meta['docker_runtime'] : [];
        $repositoryPath = (string) ($runtime['repository_checkout_path'] ?? '');
        $workingDirectory = (string) ($runtime['working_directory'] ?? $repositoryPath);
        $composePath = (string) ($runtime['generated_compose_path'] ?? '');

        if ($workingDirectory === '' || $composePath === '') {
            throw new \RuntimeException(__('This Docker runtime has not been deployed yet.'));
        }

        $runtimeDetails = $this->collectRuntimeDetails($composePath, $workingDirectory);
        $container = collect($runtimeDetails['containers'] ?? [])->first(fn (mixed $entry): bool => is_array($entry) && filled($entry['name'] ?? null));
        if (! is_array($container)) {
            throw new \RuntimeException(__('No running Docker container was found for this site.'));
        }

        $containerName = (string) ($container['name'] ?? '');
        if ($containerName === '') {
            throw new \RuntimeException(__('No running Docker container was found for this site.'));
        }

        $process = new Process(
            ['docker', 'exec', $containerName, 'sh', '-lc', $shellCommand],
            $workingDirectory
        );
        $process->setTimeout($timeoutSeconds);

        try {
            $process->run(function (string $type, string $buffer) use ($onChunk): void {
                $onChunk($buffer);
            });
        } catch (ProcessTimedOutException $e) {
            throw new \RuntimeException($this->timedOutMessage($process, ['docker', 'exec', $containerName, 'sh', '-lc', $shellCommand]), previous: $e);
        }

        return $process->getExitCode() ?? 1;
    }

    /**
     * @param  list<string>  $command
     */
    private function timedOutMessage(Process $process, array $command): string
    {
        $output = trim($process->getOutput()."\n".$process->getErrorOutput());
        $commandString = implode(' ', array_map('escapeshellarg', $command));

        return trim(implode("\n\n", array_filter([
            'Local Docker runtime command timed out after '.(int) $process->getTimeout().' seconds.',
            'Command: '.$commandString,
            $output !== '' ? "Partial output:\n".$output : null,
        ])));
    }

    /**
     * @param  list<string>  $command
     */
    private function inspectCompose(array $command, string $workingDirectory, string $label): string
    {
        try {
            $output = $this->run($command, $workingDirectory, allowFailure: true);

            return $output !== '' ? "--- {$label} ---\n".$output : '';
        } catch (\Throwable $e) {
            return "--- {$label} ---\n".$e->getMessage();
        }
    }
}

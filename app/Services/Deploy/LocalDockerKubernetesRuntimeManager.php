<?php

namespace App\Services\Deploy;

use App\Models\Site;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class LocalDockerKubernetesRuntimeManager
{
    public function __construct(
        private readonly LocalRuntimeWorkspace $workspace,
        private readonly KubernetesManifestBuilder $manifestBuilder,
        private readonly KubernetesKubectlExecutor $kubectlExecutor,
        private readonly DockerRuntimeDockerfileBuilder $dockerfileBuilder,
    ) {}

    /**
     * @return array{output: string, sha: ?string, status: string, logs: array<int, string>, context: ?string, manifest_yaml: string, workspace_path: string, repository_checkout_path: string, generated_manifest_path: string, deployment_name: string, namespace: string}
     */
    public function deploy(Site $site): array
    {
        $workspace = $this->workspace->ensure($site);
        $runtime = is_array($site->meta['kubernetes_runtime'] ?? null) ? $site->meta['kubernetes_runtime'] : [];
        $namespace = (string) ($runtime['namespace'] ?? 'default');
        $repositoryPath = $workspace['repository_path'];
        $manifestPath = $repositoryPath.'/kubernetes.dply.yaml';
        $dockerfilePath = $repositoryPath.'/Dockerfile.dply';
        $context = (string) ($runtime['context'] ?? config('kubernetes.context', 'orbstack'));
        $imageName = (string) ($runtime['image_name'] ?? 'dply/'.($site->slug ?: 'site').':latest');

        File::put($dockerfilePath, $this->dockerfileBuilder->build($site));
        $this->dockerBuild($repositoryPath, $imageName);

        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['kubernetes_runtime'] = array_merge($runtime, [
            'image_name' => $imageName,
            'last_built_at' => now()->toIso8601String(),
        ]);
        $site->forceFill(['meta' => $meta])->save();
        $site->refresh();

        $manifest = $this->manifestBuilder->build($site, $namespace);

        File::put($manifestPath, $manifest);

        $result = $this->kubectlExecutor->deploy(
            $manifest,
            $namespace,
            $this->manifestBuilder->deploymentName($site),
            config('kubernetes.kubeconfig_path'),
            $context !== '' ? $context : null,
        );

        return [
            'output' => trim(implode("\n\n", array_filter([
                'Local Docker Kubernetes deploy completed.',
                $result['output'],
            ]))),
            'sha' => $workspace['revision'],
            'status' => 'running',
            'logs' => [$result['output']],
            'context' => $result['context'],
            'manifest_yaml' => $manifest,
            'workspace_path' => $workspace['workspace_path'],
            'repository_checkout_path' => $repositoryPath,
            'generated_manifest_path' => $manifestPath,
            'deployment_name' => $this->manifestBuilder->deploymentName($site),
            'namespace' => $namespace,
        ];
    }

    private function dockerBuild(string $repositoryPath, string $imageName): void
    {
        $process = new Process([
            'docker',
            'build',
            '-f',
            'Dockerfile.dply',
            '-t',
            $imageName,
            '.',
        ], $repositoryPath);
        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful()) {
            $output = trim($process->getOutput().($process->getErrorOutput() !== '' ? "\n".$process->getErrorOutput() : ''));
            throw new \RuntimeException($output !== '' ? $output : 'Docker Kubernetes image build failed.');
        }
    }

    /**
     * @return array{status: string, output: string}
     */
    public function start(Site $site): array
    {
        return $this->scale($site, 1, 'running', 'Local Kubernetes workload started.');
    }

    /**
     * @return array{status: string, output: string}
     */
    public function stop(Site $site): array
    {
        return $this->scale($site, 0, 'stopped', 'Local Kubernetes workload scaled to zero.');
    }

    /**
     * @return array{status: string, output: string}
     */
    public function restart(Site $site): array
    {
        $runtime = is_array($site->meta['kubernetes_runtime'] ?? null) ? $site->meta['kubernetes_runtime'] : [];
        $deploymentName = (string) ($runtime['deployment_name'] ?? '');
        $namespace = (string) ($runtime['namespace'] ?? 'default');
        $output = $this->kubectl($site, ['rollout', 'restart', 'deployment/'.$deploymentName, '-n', $namespace]);

        return [
            'status' => 'running',
            'output' => trim("Local Kubernetes workload restarted.\n\n".$output),
        ];
    }

    /**
     * @return array{status: string, output: string}
     */
    public function destroy(Site $site): array
    {
        $runtime = is_array($site->meta['kubernetes_runtime'] ?? null) ? $site->meta['kubernetes_runtime'] : [];
        $manifestPath = (string) ($runtime['generated_manifest_path'] ?? '');

        if ($manifestPath === '') {
            throw new \RuntimeException('This Docker Kubernetes runtime has not been deployed yet.');
        }

        $output = $this->kubectl($site, ['delete', '-f', $manifestPath], allowFailure: true);

        return [
            'status' => 'destroyed',
            'output' => trim("Local Kubernetes workload destroyed.\n\n".$output),
        ];
    }

    /**
     * @return array{status: string, output: string}
     */
    public function status(Site $site): array
    {
        $runtime = is_array($site->meta['kubernetes_runtime'] ?? null) ? $site->meta['kubernetes_runtime'] : [];
        $deploymentName = (string) ($runtime['deployment_name'] ?? '');
        $namespace = (string) ($runtime['namespace'] ?? 'default');
        $output = $this->kubectl($site, ['get', 'deployment', $deploymentName, '-n', $namespace, '-o', 'wide'], allowFailure: true);

        return [
            'status' => 'unknown',
            'output' => trim("Local Kubernetes status refreshed.\n\n".$output),
        ];
    }

    /**
     * @return array{status: string, output: string}
     */
    public function logs(Site $site): array
    {
        $runtime = is_array($site->meta['kubernetes_runtime'] ?? null) ? $site->meta['kubernetes_runtime'] : [];
        $deploymentName = (string) ($runtime['deployment_name'] ?? '');
        $namespace = (string) ($runtime['namespace'] ?? 'default');
        $output = $this->kubectl($site, ['logs', 'deployment/'.$deploymentName, '-n', $namespace, '--tail', '200'], allowFailure: true);

        return [
            'status' => 'unknown',
            'output' => trim("Local Kubernetes logs refreshed.\n\n".$output),
        ];
    }

    /**
     * @return array{status: string, output: string}
     */
    private function scale(Site $site, int $replicas, string $status, string $prefix): array
    {
        $runtime = is_array($site->meta['kubernetes_runtime'] ?? null) ? $site->meta['kubernetes_runtime'] : [];
        $deploymentName = (string) ($runtime['deployment_name'] ?? '');
        $namespace = (string) ($runtime['namespace'] ?? 'default');
        $output = $this->kubectl($site, ['scale', 'deployment/'.$deploymentName, '--replicas='.$replicas, '-n', $namespace]);

        return [
            'status' => $status,
            'output' => trim($prefix."\n\n".$output),
        ];
    }

    /**
     * @param  list<string>  $subCommand
     */
    private function kubectl(Site $site, array $subCommand, bool $allowFailure = false): string
    {
        $runtime = is_array($site->meta['kubernetes_runtime'] ?? null) ? $site->meta['kubernetes_runtime'] : [];
        $repositoryPath = (string) ($runtime['repository_checkout_path'] ?? storage_path('app'));
        $command = [(string) config('kubernetes.kubectl_bin', 'kubectl')];

        if (($kubeconfig = trim((string) config('kubernetes.kubeconfig_path', ''))) !== '') {
            $command[] = '--kubeconfig='.$kubeconfig;
        }

        if (($context = trim((string) ($runtime['kubectl_context'] ?? $runtime['context'] ?? config('kubernetes.context', '')))) !== '') {
            $command[] = '--context='.$context;
        }

        $command = [...$command, ...$subCommand];

        $process = new Process($command, $repositoryPath);
        $process->setTimeout(300);
        $process->run();

        $output = trim($process->getOutput().($process->getErrorOutput() !== '' ? "\n".$process->getErrorOutput() : ''));

        if (! $allowFailure && ! $process->isSuccessful()) {
            throw new \RuntimeException($output !== '' ? $output : 'Docker Kubernetes command failed.');
        }

        return $output;
    }

    /**
     * @param  list<string>  $argv  Command and args after `kubectl exec … --`
     * @param  callable(string): void  $onChunk
     */
    public function execInDeploymentApp(Site $site, array $argv, int $timeoutSeconds, callable $onChunk): int
    {
        $runtime = is_array($site->meta['kubernetes_runtime'] ?? null) ? $site->meta['kubernetes_runtime'] : [];
        $deploymentName = (string) ($runtime['deployment_name'] ?? '');
        $namespace = (string) ($runtime['namespace'] ?? 'default');
        $repositoryPath = (string) ($runtime['repository_checkout_path'] ?? storage_path('app'));

        if ($deploymentName === '') {
            throw new \RuntimeException(__('This Kubernetes runtime has not been deployed yet.'));
        }

        $command = [(string) config('kubernetes.kubectl_bin', 'kubectl')];

        if (($kubeconfig = trim((string) config('kubernetes.kubeconfig_path', ''))) !== '') {
            $command[] = '--kubeconfig='.$kubeconfig;
        }

        if (($context = trim((string) ($runtime['kubectl_context'] ?? $runtime['context'] ?? config('kubernetes.context', '')))) !== '') {
            $command[] = '--context='.$context;
        }

        $command = [
            ...$command,
            'exec',
            '-n',
            $namespace,
            'deployment/'.$deploymentName,
            '--',
            ...$argv,
        ];

        $process = new Process($command, $repositoryPath);
        $process->setTimeout($timeoutSeconds);
        $process->run(function (string $type, string $buffer) use ($onChunk): void {
            $onChunk($buffer);
        });

        return $process->getExitCode() ?? 1;
    }
}

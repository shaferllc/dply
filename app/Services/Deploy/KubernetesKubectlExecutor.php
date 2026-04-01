<?php

namespace App\Services\Deploy;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final class KubernetesKubectlExecutor
{
    /**
     * @return array{output: string, revision: ?string, context: ?string}
     */
    public function deploy(
        string $manifest,
        string $namespace,
        string $deploymentName,
        ?string $kubeconfigPath = null,
        ?string $context = null,
    ): array {
        $resolvedContext = $this->resolveContext($kubeconfigPath, $context);
        $output = [];

        $this->run(array_merge(
            $this->baseCommand($kubeconfigPath, $resolvedContext),
            ['create', 'namespace', $namespace]
        ), $output, true);

        $this->run(array_merge(
            $this->baseCommand($kubeconfigPath, $resolvedContext),
            ['apply', '-f', '-']
        ), $output, false, $manifest);

        $this->run(array_merge(
            $this->baseCommand($kubeconfigPath, $resolvedContext),
            ['rollout', 'status', 'deployment/'.$deploymentName, '-n', $namespace, '--timeout='.max(30, (int) config('kubernetes.rollout_timeout_seconds', 180)).'s']
        ), $output);

        $revision = trim($this->run(array_merge(
            $this->baseCommand($kubeconfigPath, $resolvedContext),
            ['get', 'deployment', $deploymentName, '-n', $namespace, '-o', 'jsonpath={.metadata.generation}']
        ), $output));

        return [
            'output' => implode("\n", array_filter($output)),
            'revision' => $revision !== '' ? $revision : null,
            'context' => $resolvedContext,
        ];
    }

    /**
     * @param  list<string>  $command
     * @param  list<string>  $output
     */
    private function run(array $command, array &$output, bool $allowAlreadyExists = false, ?string $input = null): string
    {
        $process = new Process($command);
        $process->setTimeout(max(30, (int) config('kubernetes.command_timeout_seconds', 300)));

        if ($input !== null) {
            $process->setInput($input);
        }

        $process->run();

        $combinedOutput = trim($process->getOutput().($process->getErrorOutput() !== '' ? "\n".$process->getErrorOutput() : ''));

        if ($allowAlreadyExists && ! $process->isSuccessful() && str_contains(strtolower($combinedOutput), 'already exists')) {
            $output[] = '$ '.implode(' ', $command);
            $output[] = $combinedOutput;

            return $combinedOutput;
        }

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                "Kubernetes command failed.\nCommand: %s\n%s",
                implode(' ', $command),
                trim((new ProcessFailedException($process))->getMessage())
            ));
        }

        $output[] = '$ '.implode(' ', $command);
        if ($combinedOutput !== '') {
            $output[] = $combinedOutput;
        }

        return $combinedOutput;
    }

    /**
     * @return list<string>
     */
    private function baseCommand(?string $kubeconfigPath, ?string $context): array
    {
        $command = [(string) config('kubernetes.kubectl_bin', 'kubectl')];

        if ($kubeconfigPath !== null && trim($kubeconfigPath) !== '') {
            $command[] = '--kubeconfig='.$kubeconfigPath;
        }

        if ($context !== null && trim($context) !== '') {
            $command[] = '--context='.$context;
        }

        return $command;
    }

    private function resolveContext(?string $kubeconfigPath, ?string $context): ?string
    {
        $context = is_string($context) ? trim($context) : '';
        if ($context !== '') {
            return $context;
        }

        $configured = trim((string) config('kubernetes.context', ''));

        return $configured !== '' ? $configured : null;
    }
}

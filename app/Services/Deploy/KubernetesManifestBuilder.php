<?php

namespace App\Services\Deploy;

use App\Models\Site;
use Illuminate\Support\Str;

final class KubernetesManifestBuilder
{
    public function __construct(
        private readonly DeploymentContractBuilder $contractBuilder,
    ) {}

    public function build(Site $site, string $namespace): string
    {
        $contract = $this->contractBuilder->build($site);
        $name = $this->deploymentName($site);
        $port = $site->type?->value === 'node'
            ? (int) ($site->app_port ?: 3000)
            : 80;
        $image = (string) data_get($site->meta, 'kubernetes_runtime.image_name', 'dply/'.($site->slug ?: 'site').':latest');
        $publishedPort = (int) data_get($site->meta, 'runtime_target.publication.port', 30080);
        $serviceType = $site->runtimeTargetFamily() === 'local_orbstack_kubernetes' ? 'NodePort' : 'ClusterIP';
        $nodePortBlock = $serviceType === 'NodePort' ? "\n      nodePort: {$publishedPort}" : '';
        $configMapName = $name.'-config';
        $secretName = $name.'-secret';
        $configMapEntries = [];
        $secretEntries = [];

        foreach ($contract->secrets as $secret) {
            if (str_starts_with($secret->key, 'DPLY_')) {
                continue;
            }

            if ($secret->isSecret) {
                $secretEntries[] = '  '.$secret->key.': '.$this->base64Scalar($secret->value);
            } else {
                $configMapEntries[] = '  '.$secret->key.': '.$this->yamlScalar($secret->value);
            }
        }

        $configMapYaml = $configMapEntries === []
            ? ''
            : <<<YAML
apiVersion: v1
kind: ConfigMap
metadata:
  name: {$configMapName}
  namespace: {$namespace}
data:
{$this->indent(implode("\n", $configMapEntries), 0)}
---
YAML;
        $secretYaml = $secretEntries === []
            ? ''
            : <<<YAML
apiVersion: v1
kind: Secret
metadata:
  name: {$secretName}
  namespace: {$namespace}
type: Opaque
data:
{$this->indent(implode("\n", $secretEntries), 0)}
---
YAML;
        $envFrom = implode("\n", array_filter([
            $configMapEntries !== [] ? "          envFrom:\n            - configMapRef:\n                name: {$configMapName}" : null,
            $secretEntries !== [] ? ($configMapEntries !== []
                ? "            - secretRef:\n                name: {$secretName}"
                : "          envFrom:\n            - secretRef:\n                name: {$secretName}") : null,
        ]));

        return <<<YAML
{$configMapYaml}{$secretYaml}apiVersion: apps/v1
kind: Deployment
metadata:
  name: {$name}
  namespace: {$namespace}
spec:
  replicas: 1
  selector:
    matchLabels:
      app: {$name}
  template:
    metadata:
      labels:
        app: {$name}
    spec:
      containers:
        - name: app
          image: {$image}
          imagePullPolicy: IfNotPresent
{$envFrom}
          ports:
            - containerPort: {$port}
---
apiVersion: v1
kind: Service
metadata:
  name: {$name}
  namespace: {$namespace}
spec:
  type: {$serviceType}
  selector:
    app: {$name}
  ports:
    - port: 80
      targetPort: {$port}{$nodePortBlock}
YAML;
    }

    public function deploymentName(Site $site): string
    {
        $name = Str::slug($site->slug ?: $site->name ?: 'site');
        $name = trim(substr($name, 0, 63), '-');

        return $name !== '' ? $name : 'site';
    }

    private function yamlScalar(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '""';
    }

    private function base64Scalar(string $value): string
    {
        return base64_encode($value);
    }

    private function indent(string $value, int $spaces): string
    {
        $prefix = str_repeat(' ', $spaces);

        return collect(explode("\n", $value))
            ->map(static fn (string $line): string => $prefix.$line)
            ->implode("\n");
    }
}

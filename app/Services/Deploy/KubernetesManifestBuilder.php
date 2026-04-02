<?php

namespace App\Services\Deploy;

use App\Models\Site;
use Illuminate\Support\Str;

final class KubernetesManifestBuilder
{
    public function build(Site $site, string $namespace): string
    {
        $name = $this->deploymentName($site);
        $port = $site->type?->value === 'node'
            ? (int) ($site->app_port ?: 3000)
            : 80;
        $image = (string) data_get($site->meta, 'kubernetes_runtime.image_name', 'dply/'.($site->slug ?: 'site').':latest');
        $publishedPort = (int) data_get($site->meta, 'runtime_target.publication.port', 30080);
        $serviceType = $site->runtimeTargetFamily() === 'local_orbstack_kubernetes' ? 'NodePort' : 'ClusterIP';
        $nodePortBlock = $serviceType === 'NodePort' ? "\n      nodePort: {$publishedPort}" : '';

        return <<<YAML
apiVersion: apps/v1
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
}

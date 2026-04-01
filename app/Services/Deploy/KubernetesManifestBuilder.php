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
            : 8080;
        $image = 'dply/'.($site->slug ?: 'site').':latest';

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
          ports:
            - containerPort: {$port}
---
apiVersion: v1
kind: Service
metadata:
  name: {$name}
  namespace: {$namespace}
spec:
  selector:
    app: {$name}
  ports:
    - port: 80
      targetPort: {$port}
YAML;
    }

    public function deploymentName(Site $site): string
    {
        $name = Str::slug($site->slug ?: $site->name ?: 'site');
        $name = trim(substr($name, 0, 63), '-');

        return $name !== '' ? $name : 'site';
    }
}

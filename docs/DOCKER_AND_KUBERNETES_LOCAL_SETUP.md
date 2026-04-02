# Docker and Kubernetes local setup

This guide complements [BYO_LOCAL_SETUP.md](BYO_LOCAL_SETUP.md).

Use the BYO guide for:
- running the control plane locally
- validating SSH and Ubuntu provisioning scripts with `docker-compose.ssh-dev.yml`

Use this guide for:
- Docker runtime targets
- local Kubernetes runtime targets with Docker-backed local clusters
- the first managed-cluster path that mirrors DigitalOcean Kubernetes in the app

## Two local loops

Keep these loops separate:

1. VM provisioning validation
- Start the SSH test target from [BYO_LOCAL_SETUP.md](BYO_LOCAL_SETUP.md).
- Use this when you need to validate `apt`, `systemctl`, rendered nginx configs, firewall commands, and the existing server bootstrap scripts.

2. Container runtime validation
- Use a Docker-backed host target when you want Dply to prepare Docker runtime artifacts.
- Use a Kubernetes-backed host target when you want Dply to prepare Kubernetes manifests and runtime metadata.

## Docker target

Create a custom server and choose `Docker host` as the host target.

What Dply does today:
- marks the server as a Docker runtime target
- creates sites with the `docker_web` runtime profile
- prepares Docker Compose YAML during provisioning
- uses the Docker deploy engine to refresh the runtime artifact on deploy

Current expectation:
- Dply prepares runtime artifacts inside site metadata
- you can inspect the generated Compose configuration from the site record and deployment logs

## Docker-backed local Kubernetes target

Create a `DigitalOcean Kubernetes` server target in the app for managed-cluster metadata, or use a Kubernetes-backed host in tests and seeds.

For local cluster work:

1. Start Kubernetes in your local Docker-backed cluster runtime.
2. Create a Kubernetes-backed target in Dply.
3. Create a site on that target.
4. Run site provisioning once so Dply writes the initial manifest artifact.
5. Run a deploy to refresh the rendered manifest, apply it with `kubectl`, and wait for rollout status.

What Dply does today:
- assigns the `kubernetes_web` runtime profile to sites on Kubernetes hosts
- prepares deployment and service manifests
- applies the manifest to the current local kubeconfig or configured context with `kubectl`
- stores the rendered manifest, namespace, context, and rollout metadata on the site
- uses the Kubernetes deploy engine instead of the SSH deploy pipeline

## DigitalOcean Kubernetes notes

The first managed-cluster path in the UI is `DigitalOcean Kubernetes`.

What is captured:
- DigitalOcean credential selection
- cluster name
- target namespace

What is not validated yet:
- cluster existence via the DigitalOcean API
- kubeconfig retrieval

That means local Docker-backed clusters can now be exercised end to end from the BYO control plane, while provider-backed kubeconfig retrieval and deeper DOKS validation still need to be added.

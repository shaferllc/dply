# Fake cloud provision (dev / testing)

Use this to run the **provider wizard and job chain** (credentials, region/size, `Provision*Job` → `STATUS_READY` → `WaitForServerSshReadyJob` → `RunSetupScriptJob`) **without** calling DigitalOcean, AWS, or other vendor APIs.

## Safety

- Requires **`APP_ENV=local`** or **`testing`** (see `allowed_environments` in `config/server_provision_fake.php`) **and** `DPLY_FAKE_CLOUD_PROVISION=true`.
- Never enable this in production deployments.

## Quick start

1. Start your SSH test target (for example `docker-compose.ssh-dev.yml`).
2. Set env vars (see `.env.example` section “Fake cloud provision”).
3. Run a queue worker: `php artisan queue:work`.
4. Create a server through **Provision with a provider** as usual; jobs will point `ip_address` / `ssh_port` / `ssh_user` at the fake target instead of creating real infrastructure.

## SSH keys

By default the app **generates** key material and logs `recovery_public_key` in **local** so you can add it to the test host’s `authorized_keys`. Alternatively set **`DPLY_FAKE_CLOUD_SSH_PRIVATE_KEY`** to a PEM/OpenSSH private key that already matches the host.

**`docker-compose.ssh-dev.yml`:** Builds a small Ubuntu image (**`docker/ssh-dev/Dockerfile`**) that runs **sshd as root** so the bootstrap script (apt installs, `useradd`, `/etc` writes) can execute end-to-end against the dev container. The bundled **`docker/ssh-dev/local_fake_cloud_ed25519.pub`** is mounted read-only and installed as **`/root/.ssh/authorized_keys`** at container start; the app loads the matching private key from **`docker/ssh-dev/local_fake_cloud_ed25519`** when **`DPLY_FAKE_CLOUD_SSH_PRIVATE_KEY`** is unset. Default **`DPLY_FAKE_CLOUD_SSH_USER`** is **`root`** (the bootstrap creates the deploy user, then **`RunSetupScriptJob::applyProvisionOutcomeToServer`** flips **`ssh_user`** to **`server_provision.deploy_ssh_user`**). Rebuild the container after pulling Dockerfile changes: **`docker compose -f docker-compose.ssh-dev.yml up -d --build`**.

For **`provider_id = fake-local`** servers, **`Server::recoverySshPrivateKey()` / `operationalSshPrivateKey()`** prefer **`FakeCloudProvision::resolvedPrivateKey()`** when env/path resolves (not stored-row keys), so older fake servers created before bundled keys still SSH correctly after you add **`docker/ssh-dev`** keys — no need to delete the server row solely for key drift.

Password-only targets can use **`DPLY_FAKE_CLOUD_SSH_PASSWORD`** (stored under `meta.local_runtime.ssh_password` for `App\Services\SshConnection`). **TaskRunner remote SSH uses key auth**, not passwords — password meta does not replace **`root`** key auth for stack setup.

## Providers

VM-style providers that use **Provision → Poll IP** are intercepted: DigitalOcean, Hetzner, Linode/Akamai, Vultr, Scaleway, UpCloud, Equinix Metal, AWS EC2.

**Fly.io** is different: `RunSetupScriptJob` does not run for Fly hosts. With **`DPLY_FAKE_CLOUD_FLY_IO_UI_STUB=true`**, the Fly provision job can skip the Fly API and mark the server **ready** for journey/UI smoke only (no classic stack install).

## Related

- [LOCAL_SSH_STACK_TESTING.md](LOCAL_SSH_STACK_TESTING.md) — BYO “existing server” path with the same SSH container.

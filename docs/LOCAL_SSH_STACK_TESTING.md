# Local BYO stack testing (OpenSSH in Docker)

This is a **developer** workflow for exercising install profiles, server type, and default stack choices against a throwaway Linux SSH target, without cloud provisioning.

To exercise the **provider** wizard (DigitalOcean, AWS, …) without real APIs, see [FAKE_CLOUD_PROVISION.md](FAKE_CLOUD_PROVISION.md).

## Runtime

1. Start the optional SSH container:

   ```bash
   docker compose -f docker-compose.ssh-dev.yml up -d --build
   ```

2. The compose file maps **`127.0.0.1:2222`** to the container, which is built from **`docker/ssh-dev/Dockerfile`** (Ubuntu + sshd running as **root**) so the provisioner's bootstrap script (apt installs, `useradd`, `/etc` writes) can execute end-to-end. The bundled dev-only public key (**`docker/ssh-dev/local_fake_cloud_ed25519.pub`**) is mounted read-only and installed as **`/root/.ssh/authorized_keys`** at container start, so root SSH works out of the box. Default **`DPLY_FAKE_CLOUD_SSH_USER`** is **`root`** (the bootstrap then creates the deploy user).

3. In the app (for example at **`https://dplyi.test`** if that is where your Valet or local server points), open **Create server** → **Use an existing server** → **Host target: Standard VM / VPS**.

   Choose **Standard VM / VPS**, not **Docker host**. The classic SSH stack install path (`RunSetupScriptJob`) runs only for VM hosts (`isVmHost()`).

## Queue worker

After saving a BYO VM server, provisioning runs asynchronously: **`WaitForServerSshReadyJob`** chains into **`RunSetupScriptJob`**. Run a worker locally:

```bash
php artisan queue:work
```

(Horizon is fine if you use it in this environment.)

## Re-running setup on the same container

To force another provision pass against an already-seen host during iterative testing, enable:

- Config: `config/server_provision.php` → **`force_reinstall`**
- Env: **`DPLY_SERVER_PROVISION_FORCE_REINSTALL=true`**

## Fake cloud provision and `root` SSH

With **`DPLY_FAKE_CLOUD_PROVISION`** ([FAKE_CLOUD_PROVISION.md](FAKE_CLOUD_PROVISION.md)), **`ApplyFakeCloudProvisionAsReady`** loads **`docker/ssh-dev/local_fake_cloud_ed25519`** when **`DPLY_FAKE_CLOUD_SSH_PRIVATE_KEY`** is unset, and the dev container authorizes that pubkey for **`root`** so the bootstrap script runs end-to-end. Rebuild the container after pulling Dockerfile changes: **`docker compose -f docker-compose.ssh-dev.yml up -d --build`**.

If you remove the bundled key file or use a custom host, set **`DPLY_FAKE_CLOUD_SSH_PRIVATE_KEY`** (or **`DPLY_FAKE_CLOUD_SSH_PRIVATE_KEY_PATH`**) and install the matching public key on the host's **`root`** account (without it, SSH will **time out** at the TaskRunner step).

## Limitations

The dev image installs only enough to run sshd; the bootstrap script then `apt-get install`s its own dependencies on first run, which mirrors a fresh cloud VM but takes a few minutes per fresh container.

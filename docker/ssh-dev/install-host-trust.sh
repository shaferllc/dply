#!/bin/sh
# Boot-time setup: install the bundled SSH pubkey for root, trust the host's Valet CA,
# and ensure the `dply` deploy user exists with that same key authorized — so the dev
# container stays usable after `docker compose down && up` without re-running bootstrap.
# Mounted files come from docker-compose.ssh-dev.yml.
set -eu

if [ -r /etc/ssh/authorized_keys.pub ]; then
    install -o root -g root -m 600 /etc/ssh/authorized_keys.pub /root/.ssh/authorized_keys

    # Ensure `dply` exists and has the same key authorized so connections targeting
    # ssh_user='dply' (post-bootstrap) keep working across container recreations.
    if ! id -u dply >/dev/null 2>&1; then
        useradd -m -s /bin/bash -G sudo dply
        printf '%s\n' 'dply ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/90-dply-user
        chmod 440 /etc/sudoers.d/90-dply-user
    fi
    install -d -m 700 -o dply -g dply /home/dply/.ssh
    install -m 600 -o dply -g dply /etc/ssh/authorized_keys.pub /home/dply/.ssh/authorized_keys
fi

if [ -r /usr/local/share/ca-certificates/valet-ca.crt ]; then
    update-ca-certificates --fresh >/dev/null 2>&1 || true
fi

# systemd-user-sessions is masked in this container image; without it, /run/nologin
# created during early boot is never cleared and blocks non-root SSH (e.g. the dply
# deploy user the bootstrap script creates). Drop it once boot reaches multi-user.target.
rm -f /run/nologin

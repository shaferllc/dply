#!/bin/sh
# Boot-time setup: install the bundled SSH pubkey for root and trust the host's Valet CA.
# Both files are mounted read-only by docker-compose.ssh-dev.yml.
set -eu

if [ -r /etc/ssh/authorized_keys.pub ]; then
    install -o root -g root -m 600 /etc/ssh/authorized_keys.pub /root/.ssh/authorized_keys
fi

if [ -r /usr/local/share/ca-certificates/valet-ca.crt ]; then
    update-ca-certificates --fresh >/dev/null 2>&1 || true
fi

# systemd-user-sessions is masked in this container image; without it, /run/nologin
# created during early boot is never cleared and blocks non-root SSH (e.g. the dply
# deploy user the bootstrap script creates). Drop it once boot reaches multi-user.target.
rm -f /run/nologin

#!/usr/bin/env bash
# Shared base packages + dply deploy user for control-plane VMs.
set -euo pipefail

export DEBIAN_FRONTEND=noninteractive

apt-get update -y
apt-get install -y --no-install-recommends \
  ca-certificates curl git jq unzip ufw \
  software-properties-common apt-transport-https gnupg

if ! id -u dply >/dev/null 2>&1; then
  useradd -m -s /bin/bash dply
fi
mkdir -p /home/dply/.ssh
chmod 700 /home/dply/.ssh
if [[ -f /root/.ssh/authorized_keys ]]; then
  cp /root/.ssh/authorized_keys /home/dply/.ssh/authorized_keys
  chmod 600 /home/dply/.ssh/authorized_keys
  chown -R dply:dply /home/dply/.ssh
fi

# Passwordless sudo for deploy bootstrap / supervisor sync
echo 'dply ALL=(ALL) NOPASSWD:ALL' >/etc/sudoers.d/dply
chmod 440 /etc/sudoers.d/dply

hostnamectl set-hostname "${DPLY_HOSTNAME:-vultr}"

echo "common bootstrap ok on $(hostname)"

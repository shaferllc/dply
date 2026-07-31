#!/usr/bin/env bash
# Install Redis bound to the VPC private IP.
set -euo pipefail

export DEBIAN_FRONTEND=noninteractive
PRIVATE_IP="${DPLY_PRIVATE_IP:?}"
REDIS_PASS="${DPLY_REDIS_PASSWORD:?}"
VPC_CIDR="${DPLY_VPC_CIDR:-10.50.0.0/24}"

apt-get install -y --no-install-recommends redis-server

CONF=/etc/redis/redis.conf
sed -i "s/^bind .*/bind ${PRIVATE_IP}/" "$CONF"
sed -i "s/^protected-mode .*/protected-mode yes/" "$CONF"
if grep -qE '^#?requirepass ' "$CONF"; then
  sed -i "s/^#\\?requirepass .*/requirepass ${REDIS_PASS}/" "$CONF"
else
  echo "requirepass ${REDIS_PASS}" >>"$CONF"
fi
# Prefer no supervised systemd quirks on Ubuntu package defaults
sed -i 's/^supervised .*/supervised systemd/' "$CONF" || true

systemctl enable --now redis-server
systemctl restart redis-server

ufw --force reset >/dev/null 2>&1 || true
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp
ufw allow from "${VPC_CIDR}" to any port 6379 proto tcp
ufw --force enable

redis-cli -h "${PRIVATE_IP}" -a "${REDIS_PASS}" ping | grep -q PONG
echo "redis bootstrap ok listen=${PRIVATE_IP}"

#!/usr/bin/env bash
# Install Postgres listening on the VPC private IP only.
set -euo pipefail

export DEBIAN_FRONTEND=noninteractive
PRIVATE_IP="${DPLY_PRIVATE_IP:?}"
DB_NAME="${DPLY_DB_NAME:-dply}"
DB_USER="${DPLY_DB_USER:-dply}"
DB_PASS="${DPLY_DB_PASSWORD:?}"
VPC_CIDR="${DPLY_VPC_CIDR:-10.50.0.0/24}"

apt-get install -y --no-install-recommends postgresql postgresql-contrib

PG_VER="$(ls /etc/postgresql | sort -V | tail -1)"
CONF="/etc/postgresql/${PG_VER}/main/postgresql.conf"
HBA="/etc/postgresql/${PG_VER}/main/pg_hba.conf"

sed -i "s/^#\\?listen_addresses.*/listen_addresses = '${PRIVATE_IP}'/" "$CONF"
if ! grep -q "dply-control-plane" "$HBA"; then
  cat >>"$HBA" <<EOF

# dply-control-plane
host    all             all             ${VPC_CIDR}            scram-sha-256
EOF
fi

systemctl enable --now postgresql
systemctl restart postgresql

sudo -u postgres psql -v ON_ERROR_STOP=1 -c "DO \$\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '${DB_USER}') THEN CREATE ROLE ${DB_USER} LOGIN PASSWORD '${DB_PASS}'; ELSE ALTER ROLE ${DB_USER} WITH PASSWORD '${DB_PASS}'; END IF; END \$\$;"

if ! sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='${DB_NAME}'" | grep -q 1; then
  sudo -u postgres psql -v ON_ERROR_STOP=1 -c "CREATE DATABASE ${DB_NAME} OWNER ${DB_USER};"
fi
sudo -u postgres psql -v ON_ERROR_STOP=1 -c "GRANT ALL PRIVILEGES ON DATABASE ${DB_NAME} TO ${DB_USER};"

ufw --force reset >/dev/null 2>&1 || true
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp
ufw allow from "${VPC_CIDR}" to any port 5432 proto tcp
ufw --force enable

echo "postgres bootstrap ok listen=${PRIVATE_IP} db=${DB_NAME}"

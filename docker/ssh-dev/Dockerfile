# Local fake-cloud SSH target — boots systemd as PID 1 so the provisioner's
# bootstrap script (apt installs, useradd, systemctl enable/start, /etc writes)
# behaves the same way as on a fresh Ubuntu cloud VM. Requires the compose
# privileged + cgroup mount setup in docker-compose.ssh-dev.yml.
#
# Pinned to ubuntu:24.04 (noble) to match what dply provisions on
# DigitalOcean by default — see ProvisionDigitalOceanDropletJob:52
# (`ubuntu-24-04-x64`). Keep these in lockstep so a script that
# passes locally will also pass on a real droplet without surprise
# package-availability or systemd-unit differences.
FROM ubuntu:24.04

ENV container=docker
ARG DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        systemd systemd-sysv dbus dbus-user-session \
        openssh-server sudo \
        ca-certificates curl gnupg iproute2 lsb-release locales \
 # ─── DO ubuntu-24-04-x64 cloud-image package set ──────────────────
 # Mirror the package list a freshly-spun DigitalOcean droplet has on
 # first boot. Scripts that "just work" on droplets but fail locally
 # are almost always tripping over one of these missing utilities.
 # Sourced from a `dpkg --get-selections` dump on a real DO droplet,
 # trimmed to what the bootstrap script and workspace shells touch.
        cloud-init cloud-initramfs-growroot cloud-guest-utils \
        netplan.io netbase ifupdown isc-dhcp-client \
        cron rsyslog logrotate \
        wget dnsutils netcat-openbsd telnet \
        unattended-upgrades update-notifier-common landscape-common \
        python3 python3-yaml python3-requests python3-apt \
        software-properties-common \
        tzdata xz-utils bzip2 unzip zip \
        less rsync \
        htop iotop \
        git vim-tiny nano \
        man-db manpages \
 # Pre-install common bootstrap deps so they survive `docker compose down && up`.
 # On a real cloud VM the bootstrap installs these and they persist for the life
 # of the box; in our throwaway dev container they get wiped on recreate, which
 # makes ufw/firewall, supervisor/daemons, etc. workspaces useless after a reset.
 # Pinning them in the image is cheap (they're already in the bootstrap path) and
 # keeps the workspace UIs functional immediately.
        ufw iptables \
        supervisor \
        fail2ban \
 && rm -rf /var/lib/apt/lists/* \
 && locale-gen en_US.UTF-8 \
 # DO droplets default the timezone to UTC; do the same so timestamps
 # in logs (rsyslog, systemd-journal) match what an operator sees on
 # a real droplet.
 && ln -sf /usr/share/zoneinfo/UTC /etc/localtime \
 && echo "Etc/UTC" > /etc/timezone \
 # DO cloud images ship with /etc/update-motd.d/00-header etc. that
 # aren't critical, but matching the motd skeleton avoids surprising
 # operators who SSH in expecting the standard Ubuntu welcome banner.
 && (printf 'Welcome to Ubuntu 24.04 LTS (dply ssh-dev container)\n\n  This container mirrors DigitalOcean ubuntu-24-04-x64.\n  Local fake-cloud target — not a real droplet.\n\n' > /etc/motd || true)

# DO droplets typically have a non-root sudo user (`ubuntu` on cloud
# images, sometimes `root`-only). Add a passwordless-sudo `ubuntu`
# user so scripts that assume `su - ubuntu` or `sudo -u ubuntu`
# work the same way as on a real droplet. Root SSH still works for
# the bootstrap path; this is only for parity with cloud images.
RUN useradd -m -s /bin/bash -G sudo ubuntu \
 && echo 'ubuntu ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/90-ubuntu \
 && chmod 0440 /etc/sudoers.d/90-ubuntu \
 && mkdir -p /home/ubuntu/.ssh \
 && chmod 700 /home/ubuntu/.ssh \
 && chown -R ubuntu:ubuntu /home/ubuntu/.ssh

# Pre-install the ondrej/php PPA + PHP 8.4 packages so the bootstrap script's
# skip-if-already-present checks short-circuit the launchpad fetch path
# entirely. Docker on macOS regularly fails to reach
# ppa.launchpadcontent.net (185.125.190.*) due to its NAT/IPv4 routing,
# which would otherwise stall fake-cloud provisioning for ~80s before
# bombing out. Baking the artifacts into the image makes the local UX
# instant and matches what a "fresh + already-bootstrapped" cloud VM
# would look like to dply.
RUN install -d -m 0755 /etc/apt/keyrings \
 && curl -fsSL --retry 5 --retry-delay 3 --max-time 90 \
        "https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x14aa40ec0831756756d7f66c4f4ea0aae5267a6c" \
        | gpg --dearmor --yes -o /etc/apt/keyrings/ondrej-php.gpg \
 && chmod 0644 /etc/apt/keyrings/ondrej-php.gpg \
 && echo "deb [signed-by=/etc/apt/keyrings/ondrej-php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble main" \
        > /etc/apt/sources.list.d/ondrej-php.list \
 && apt-get update \
 && apt-get install -y --no-install-recommends \
        php8.4-cli php8.4-fpm php8.4-common \
        php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip \
        php8.4-intl php8.4-bcmath php8.4-opcache php8.4-mysql \
 && rm -rf /var/lib/apt/lists/*

# Strip systemd units that don't make sense in a container so boot stays fast and clean.
RUN find /lib/systemd/system/sysinit.target.wants -type l ! -name 'systemd-tmpfiles-setup*' -delete \
 && rm -f /lib/systemd/system/multi-user.target.wants/* \
          /etc/systemd/system/*.wants/* \
          /lib/systemd/system/local-fs.target.wants/* \
          /lib/systemd/system/sockets.target.wants/*udev* \
          /lib/systemd/system/sockets.target.wants/*initctl* \
          /lib/systemd/system/basic.target.wants/* \
          /lib/systemd/system/anaconda.target.wants/* \
 && systemctl mask \
        systemd-firstboot.service \
        systemd-udevd.service \
        systemd-modules-load.service \
        getty@.service \
        getty.target \
 # systemd-user-sessions clears /run/nologin once boot is multi-user — without it,
 # PAM keeps blocking non-root SSH (the dply deploy user) with "System is booting up".
 && systemctl enable systemd-user-sessions.service

# Configure sshd: listen on 2222, root login by key only.
RUN mkdir -p /var/run/sshd /root/.ssh \
 && chmod 700 /root/.ssh \
 && sed -ri 's/^#?PermitRootLogin.*/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config \
 && sed -ri 's/^#?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config \
 && sed -ri 's/^#?Port .*/Port 2222/' /etc/ssh/sshd_config \
 && ssh-keygen -A \
 && systemctl enable ssh

# Boot-time install of the host's authorized_keys + Valet CA (mounted by compose).
COPY install-host-trust.sh /usr/local/sbin/install-host-trust.sh
COPY install-host-trust.service /etc/systemd/system/install-host-trust.service
RUN chmod +x /usr/local/sbin/install-host-trust.sh \
 && systemctl enable install-host-trust.service

EXPOSE 2222
STOPSIGNAL SIGRTMIN+3

ENTRYPOINT ["/sbin/init"]

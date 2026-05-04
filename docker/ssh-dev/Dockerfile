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
#
# The platform is pinned to linux/amd64 because DO droplets are x86_64
# (note the "-x64" in the image slug), and several apt sources we rely
# on — most notably packages.sury.org/php — only ship a meaningful
# package set for amd64 on noble. On Apple Silicon hosts this runs
# under QEMU emulation; that's an acceptable cost for staying faithful
# to the cloud target and avoiding arch-specific package gaps.
FROM --platform=linux/amd64 ubuntu:24.04

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
#
# Recent ubuntu:24.04 base images already ship with a UID-1000 `ubuntu`
# user (matching the cloud image), so create-if-missing and then ensure
# group membership / home / .ssh regardless of which side created it.
RUN id -u ubuntu >/dev/null 2>&1 || useradd -m -s /bin/bash ubuntu \
 && usermod -aG sudo ubuntu \
 && echo 'ubuntu ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/90-ubuntu \
 && chmod 0440 /etc/sudoers.d/90-ubuntu \
 && install -d -m 0700 -o ubuntu -g ubuntu /home/ubuntu/.ssh

# Pre-install Ondřej Surý's PHP 8.4 packages so the bootstrap script's
# skip-if-already-present checks short-circuit the launchpad fetch path
# entirely.
#
# We pull from packages.sury.org (Surý's own apt repo) instead of the
# Launchpad PPA mirror. Both ship the same packages — Surý maintains
# them — but Docker on macOS routinely cannot reach
# ppa.launchpadcontent.net (185.125.190.*) due to NAT/IPv4 routing,
# which would otherwise stall fake-cloud provisioning ~80s before
# timing out. sury.org rides a Fastly-fronted CDN that's reachable
# from the same Docker network in <1s, so swapping the source
# eliminates the flake without changing what gets installed.
RUN install -d -m 0755 /etc/apt/keyrings \
 && curl -fsSL --retry 5 --retry-delay 3 --max-time 90 \
        https://packages.sury.org/php/apt.gpg \
        -o /etc/apt/keyrings/sury-php.gpg \
 && chmod 0644 /etc/apt/keyrings/sury-php.gpg \
 && echo "deb [signed-by=/etc/apt/keyrings/sury-php.gpg] https://packages.sury.org/php/ noble main" \
        > /etc/apt/sources.list.d/sury-php.list \
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

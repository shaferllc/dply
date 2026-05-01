# Local fake-cloud SSH target — boots systemd as PID 1 so the provisioner's
# bootstrap script (apt installs, useradd, systemctl enable/start, /etc writes)
# behaves the same way as on a fresh Ubuntu cloud VM. Requires the compose
# privileged + cgroup mount setup in docker-compose.ssh-dev.yml.
FROM ubuntu:24.04

ENV container=docker
ARG DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        systemd systemd-sysv dbus dbus-user-session \
        openssh-server sudo \
        ca-certificates curl iproute2 lsb-release locales \
 && rm -rf /var/lib/apt/lists/* \
 && locale-gen en_US.UTF-8

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
        getty.target

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

<?php

namespace App\Services\Servers;

class HaproxyConfigBuilder
{
    public function build(string $frontendName = 'http-in', string $backendName = 'apps'): string
    {
        return <<<HAPROXY
global
    log /dev/log local0
    log /dev/log local1 notice
    chroot /var/lib/haproxy
    stats socket /run/haproxy/admin.sock mode 660 level admin
    stats timeout 30s
    user haproxy
    group haproxy
    daemon

defaults
    mode http
    log global
    option httplog
    option dontlognull
    timeout connect 5s
    timeout client 30s
    timeout server 30s
    retries 3

frontend {$frontendName}
    bind :80
    bind :443 ssl crt /etc/ssl/private/dply-placeholder.pem
    default_backend {$backendName}

backend {$backendName}
    option httpchk GET /health
    balance roundrobin
    server app1 127.0.0.1:8080 check
    # Add more upstreams as apps are attached to this load balancer.
HAPROXY;
    }
}

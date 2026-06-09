<x-server-workspace-layout
    :server="$server"
    active="security-digest"
    :title="__('Security digest')"
    :description="__('SSH auth failures, fail2ban jails, firewall posture, and sshd hardening — preview what is shipping next.')"
>
    <x-security-digest-preview-panel :server="$server" />
</x-server-workspace-layout>

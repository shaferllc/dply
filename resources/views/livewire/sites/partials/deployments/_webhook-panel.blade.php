<section id="webhook" class="scroll-mt-24">
    <livewire:sites.repository
        :server="$server"
        :site="$site"
        :embedded="true"
        lockedTab="webhook"
        wire:key="deployments-webhook-{{ $site->id }}"
    />
</section>

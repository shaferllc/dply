<div class="space-y-6">
    @include('livewire.sites.partials.edge.previews')
    @unless ($edgeIsPreviewChild)
        @include('livewire.sites.partials.edge.preview-settings')
    @endunless
    @include('livewire.partials.confirm-action-modal')
</div>

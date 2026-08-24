<div>
    <x-livewire-validation-errors />

    {{-- doc-route on the trail: the header's Documentation button was a second
         way to the same page, one row lower. --}}
    @push('breadcrumbs')
        <x-breadcrumb-trail doc-route="docs.index" :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Profile'), 'href' => route('settings.profile'), 'icon' => 'user-circle'],
            ['label' => __('Notification channels'), 'href' => route('profile.notification-channels'), 'icon' => 'bell-alert'],
            ['label' => __('Bulk assign'), 'icon' => 'rectangle-stack'],
        ]" />
    @endpush

    {{-- dense: one-line header, no actions row. The organization is named in the
         description instead of repeated as a badge beside it. --}}
    <x-profile-shell
        dense
        :title="__('Bulk assign')"
        :description="$currentOrganization
            ? __('Send events from :org to the channels you can manage.', ['org' => $currentOrganization->name])
            : __('Send events to the channels you can manage.')"
        icon="heroicon-o-paper-airplane"
    >
        @include($bodyPartial)
    </x-profile-shell>

    <x-notification-channel-quick-add-modal
        :show="$showQuickNotificationChannelModal"
        :types="$quickAddTypes"
        :current-type="$quick_new_type"
        :can-manage-organization-notification-channels="$canManageOrganizationNotificationChannels"
        :title="__('Quick add channel')"
        :description="__('Create a destination without leaving this assignment flow.')"
    />
</div>

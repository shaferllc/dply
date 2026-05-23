            @livewire(\App\Livewire\Notifications\ResourceSummary::class, [
                'resource' => $server,
                'heading' => __('Database and server notifications'),
                'manageUrl' => route('profile.notification-channels.bulk-assign', ['server' => $server->id]),
            ], key('resource-summary-databases-'.$server->id))

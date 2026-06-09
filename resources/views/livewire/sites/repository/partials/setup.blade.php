{{-- First-deploy setup wizard, embedded as the Repository "Set up" tab. The
     SiteSetup component loads its own data and runs its own actions (scan poll,
     env import, resource provisioning, deploy); `embedded` suppresses its page
     chrome so it lives inside the Repository shell. --}}
@livewire(
    \App\Livewire\Sites\SiteSetup::class,
    ['server' => $server, 'site' => $site, 'embedded' => true],
    key('site-setup-'.$site->id)
)

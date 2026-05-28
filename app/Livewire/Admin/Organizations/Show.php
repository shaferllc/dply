<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Organizations;

use App\Livewire\Admin\Concerns\AuthorizesPlatformAdmin;
use App\Livewire\Admin\Concerns\ManagesAdminFlagToggles;
use App\Models\Organization;
use App\Support\Admin\AdminFeatureFlags;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Layout('layouts.admin')]
class Show extends Component
{
    use AuthorizesPlatformAdmin;
    use ManagesAdminFlagToggles;

    public Organization $organization;

    #[Url(as: 'tab', except: 'vm-servers')]
    public string $tab = 'vm-servers';

    public function mount(Organization $organization): void
    {
        $this->mountAuthorizesPlatformAdmin();
        $this->organization = $organization;
        $this->tab = AdminFeatureFlags::resolveOrgTab($this->tab);

        if (AdminFeatureFlags::productLineTitle($this->tab) === null) {
            throw new NotFoundHttpException;
        }
    }

    public function setTab(string $tab): void
    {
        $tab = AdminFeatureFlags::resolveOrgTab($tab);
        if (AdminFeatureFlags::productLineTitle($tab) === null) {
            return;
        }

        $this->tab = $tab;
    }

    protected function resolveFlagOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function render(): View
    {
        $this->authorizePlatformAdmin();

        $groups = [];
        foreach (AdminFeatureFlags::groupsForProductLine($this->tab) as $title => $flags) {
            $orgScoped = [];
            foreach ($flags as $key => $label) {
                if (! AdminFeatureFlags::isGlobalNamespace($key) && ! AdminFeatureFlags::isPlatformOnlyOrgFlag($key)) {
                    $orgScoped[$key] = $label;
                }
            }

            if ($orgScoped !== []) {
                $groups[] = [
                    'title' => $title,
                    'flags' => $this->orgFlagEntries($this->organization, $orgScoped),
                ];
            }
        }

        return view('livewire.admin.organizations.show', [
            'organization' => $this->organization,
            'tab' => $this->tab,
            'lineTitle' => AdminFeatureFlags::productLineTitle($this->tab),
            'groups' => $groups,
            'overrideCount' => AdminFeatureFlags::orgOverrideCount($this->organization),
        ]);
    }
}

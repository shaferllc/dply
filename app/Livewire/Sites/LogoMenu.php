<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Livewire\Sites\Concerns\ManagesSiteLogo;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Self-contained site-logo avatar + edit menu (upload / pull favicon / remove).
 *
 * Nested component on purpose: the widget renders inside surfaces owned by
 * many different page components (the persisted workspace sidebar, the General
 * tab's Overview header), and only this component needs the ManagesSiteLogo
 * wiring — the hosts stay logo-agnostic.
 */
class LogoMenu extends Component
{
    use ManagesSiteLogo;

    public Site $site;

    /** Sizing classes for the avatar, so each surface picks its own scale. */
    public string $avatarClass = 'h-11 w-11 text-base';

    public function mount(Site $site, string $avatarClass = 'h-11 w-11 text-base'): void
    {
        $this->site = $site;
        $this->avatarClass = $avatarClass;
    }

    public function render(): View
    {
        return view('livewire.sites.logo-menu');
    }
}

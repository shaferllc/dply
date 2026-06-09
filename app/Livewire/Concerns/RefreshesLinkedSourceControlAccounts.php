<?php

namespace App\Livewire\Concerns;

use App\Services\SourceControl\SourceControlRepositoryBrowser;
use Livewire\Attributes\On;

trait RefreshesLinkedSourceControlAccounts
{
    #[On('source-control-linked')]
    public function refreshLinkedSourceControlAccounts(): void
    {
        if (! property_exists($this, 'linkedSourceControlAccounts')) {
            return;
        }

        $user = auth()->user();
        if ($user === null) {
            return;
        }

        $this->linkedSourceControlAccounts = app(SourceControlRepositoryBrowser::class)
            ->accountsForUser($user);

        $this->afterLinkedSourceControlAccountsRefreshed();
    }

    protected function afterLinkedSourceControlAccountsRefreshed(): void
    {
        //
    }
}

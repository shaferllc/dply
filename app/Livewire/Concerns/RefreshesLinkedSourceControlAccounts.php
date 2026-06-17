<?php

namespace App\Livewire\Concerns;

use App\Services\SourceControl\SourceControlRepositoryBrowser;
use Livewire\Attributes\On;

/**
 * @property list<array<string, mixed>> $linkedSourceControlAccounts
 */
trait RefreshesLinkedSourceControlAccounts
{
    #[On('source-control-linked')]
    public function refreshLinkedSourceControlAccounts(): void
    {
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

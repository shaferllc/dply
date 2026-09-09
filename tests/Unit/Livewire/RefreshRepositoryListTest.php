<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\RefreshRepositoryListTest;

use App\Livewire\Concerns\Sites\ConfiguresGitRepository;
use App\Modules\SourceControl\Services\SourceControlRepositoryBrowser;

/**
 * The picker fetches once per account selection and then holds the list in a
 * Livewire property, so a repo created afterwards never appeared. This asserts
 * the refresh action exists, is public (Livewire can only call public methods),
 * and re-runs the fetch.
 */
test('the concern exposes a public refresh action', function () {
    $method = new \ReflectionMethod(ConfiguresGitRepository::class, 'refreshRepositoryList');

    expect($method->isPublic())->toBeTrue();

    // wire:click="refreshRepositoryList" passes no arguments, so the browser
    // must be resolvable by Livewire's container injection — i.e. typed.
    $params = $method->getParameters();
    expect($params)->toHaveCount(1)
        ->and($params[0]->getType()?->getName())->toBe(SourceControlRepositoryBrowser::class);
});

test('refreshing re-fetches and reports the delta', function () {
    $component = new class
    {
        use ConfiguresGitRepository;

        public array $toasts = [];

        public function toastSuccess(string $message): void
        {
            $this->toasts[] = $message;
        }

        // Stand in for the real fetch so the test never touches the network.
        protected function refreshRepositories(SourceControlRepositoryBrowser $browser): void
        {
            $this->availableRepositories = [
                ['label' => 'tshafer/divineiv', 'url' => 'https://github.com/tshafer/divineiv.git', 'branch' => 'main'],
                ['label' => 'tshafer/brand-new', 'url' => 'https://github.com/tshafer/brand-new.git', 'branch' => 'main'],
            ];
        }
    };

    $component->availableRepositories = [
        ['label' => 'tshafer/divineiv', 'url' => 'https://github.com/tshafer/divineiv.git', 'branch' => 'main'],
    ];

    $component->refreshRepositoryList(app(SourceControlRepositoryBrowser::class));

    expect($component->availableRepositories)->toHaveCount(2)
        ->and($component->toasts[0])->toContain('1 new repository');
});

test('no new repos reports up to date', function () {
    $component = new class
    {
        use ConfiguresGitRepository;

        public array $toasts = [];

        public function toastSuccess(string $message): void
        {
            $this->toasts[] = $message;
        }

        protected function refreshRepositories(SourceControlRepositoryBrowser $browser): void
        {
            // Same list back.
        }
    };

    $component->availableRepositories = [
        ['label' => 'tshafer/divineiv', 'url' => 'https://github.com/tshafer/divineiv.git', 'branch' => 'main'],
    ];

    $component->refreshRepositoryList(app(SourceControlRepositoryBrowser::class));

    expect($component->toasts[0])->toContain('up to date');
});

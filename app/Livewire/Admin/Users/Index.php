<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Users;

use App\Livewire\Admin\Concerns\AuthorizesPlatformAdmin;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesPlatformAdmin;
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        $this->mountAuthorizesPlatformAdmin();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $this->authorizePlatformAdmin();

        $users = User::query()
            ->with('organizations:id,name')
            ->when(trim($this->search) !== '', function (Builder $query): void {
                $term = '%'.Str::lower(trim($this->search)).'%';
                $query->where(function (Builder $inner) use ($term): void {
                    $inner->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$term]);
                });
            })
            ->orderBy('name')
            ->paginate(25);

        return view('livewire.admin.users.index', [
            'users' => $users,
        ]);
    }
}

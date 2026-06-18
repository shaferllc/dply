<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Impersonation\Impersonator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Start / stop platform-admin impersonation. Controller routes (not Livewire)
 * because they swap the auth guard — same class of action as login/logout.
 */
class ImpersonationController extends Controller
{
    public function start(User $user, Impersonator $impersonator): RedirectResponse
    {
        Gate::authorize('viewPlatformAdmin');

        try {
            $impersonator->start(Auth::user(), $user);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('dashboard')->with('status', __('You are now viewing as :name.', [
            'name' => $user->name,
        ]));
    }

    public function leave(Impersonator $impersonator): RedirectResponse
    {
        $admin = $impersonator->leave();

        if ($admin === null) {
            return redirect('/');
        }

        return redirect()->route('admin.overview')->with('status', __('Impersonation ended.'));
    }
}

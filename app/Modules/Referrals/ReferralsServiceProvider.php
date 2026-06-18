<?php

declare(strict_types=1);

namespace App\Modules\Referrals;

use App\Modules\Referrals\Livewire\Referrals;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Referrals module wiring (docs/adr/modular-monolith-structure.md).
 *
 * The other touchpoints stay registered where they were, now pointing at the
 * moved classes: the invoice listener via Event::listen in AppServiceProvider,
 * and CaptureReferralCode in the bootstrap/app.php middleware stack.
 *
 * Only the full-page component needs handling here: moving it out of App\Livewire
 * breaks Livewire's class->name derivation at render time, so it is registered
 * under its original auto-derived name (profile.referrals).
 */
class ReferralsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Livewire::component('profile.referrals', Referrals::class);
    }
}

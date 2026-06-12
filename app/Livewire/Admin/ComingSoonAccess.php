<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Http\Middleware\RedirectGuestsToComingSoon;
use App\Livewire\Admin\Concerns\AuthorizesPlatformAdmin;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\ComingSoonAllowedIp;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Platform-admin management of the coming-soon IP allow-list: addresses (and
 * CIDR ranges) that see the full site while the gate is on. The effective list
 * is these managed rows ∪ the COMING_SOON_ALLOWED_IPS env entries (shown here
 * read-only). See {@see RedirectGuestsToComingSoon}.
 */
#[Layout('layouts.admin')]
class ComingSoonAccess extends Component
{
    use AuthorizesPlatformAdmin;
    use DispatchesToastNotifications;

    public string $ip = '';

    public string $label = '';

    public function mount(): void
    {
        $this->mountAuthorizesPlatformAdmin();
    }

    public function addIp(): void
    {
        $this->validate([
            'ip' => ['required', 'string', 'max:64'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        $ip = trim($this->ip);
        if (! $this->looksLikeIpOrCidr($ip)) {
            $this->addError('ip', __('Enter a valid IPv4/IPv6 address or CIDR range.'));

            return;
        }

        ComingSoonAllowedIp::query()->firstOrCreate(
            ['ip' => $ip],
            ['label' => trim($this->label) !== '' ? trim($this->label) : null, 'created_by' => (string) auth()->id()],
        );

        $this->reset('ip', 'label');
        $this->toastSuccess(__('Added :ip to the allow-list.', ['ip' => $ip]));
    }

    public function addMyIp(): void
    {
        $this->ip = (string) request()->ip();
        $this->addIp();
    }

    public function remove(int $id): void
    {
        ComingSoonAllowedIp::query()->whereKey($id)->delete();
        $this->toastSuccess(__('Removed from the allow-list.'));
    }

    private function looksLikeIpOrCidr(string $value): bool
    {
        if (str_contains($value, '/')) {
            [$addr, $bits] = explode('/', $value, 2);

            return filter_var($addr, FILTER_VALIDATE_IP) !== false && ctype_digit($bits);
        }

        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    public function render(): View
    {
        return view('livewire.admin.coming-soon-access', [
            'rows' => ComingSoonAllowedIp::query()->latest()->get(),
            'envIps' => (array) config('dply.coming_soon_allowed_ips', []),
            'gateOn' => config('dply.coming_soon') !== false,
            'yourIp' => (string) request()->ip(),
        ]);
    }
}

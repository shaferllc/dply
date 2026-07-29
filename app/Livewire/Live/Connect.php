<?php

declare(strict_types=1);

namespace App\Livewire\Live;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Live\Concerns\InteractsWithProductionData;
use App\Models\ProductionDataConnection;
use App\Services\ProductionData\ProductionApiClient;
use App\Services\ProductionData\ProductionApiException;
use App\Services\ProductionData\ProductionDataMirror;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Connect extends Component
{
    use DispatchesToastNotifications;
    use InteractsWithProductionData;

    public string $baseUrl = '';

    public ?string $deviceCode = null;

    public ?string $userCode = null;

    public ?string $verificationUri = null;

    public ?string $verificationUriComplete = null;

    public int $pollInterval = 2;

    public string $status = 'idle';

    public ?string $errorMessage = null;

    public function mount(): void
    {
        $this->baseUrl = ProductionDataMirror::defaultBaseUrl();

        if ($this->productionConnection !== null) {
            $this->redirect(route('live.sites.index'), navigate: true);
        }
    }

    public function startDeviceFlow(): void
    {
        $this->resetErrorBag();
        $this->errorMessage = null;

        $base = rtrim(trim($this->baseUrl), '/');
        if ($base === '' || ! filter_var($base, FILTER_VALIDATE_URL)) {
            $this->addError('baseUrl', __('Enter a valid production API origin (https://…).'));

            return;
        }

        try {
            $client = ProductionApiClient::unauthenticated($base);
            $started = $client->startDeviceAuthorization();
        } catch (ProductionApiException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        } catch (\Throwable $e) {
            $this->errorMessage = __('Could not reach :host — :error', [
                'host' => $base,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $this->baseUrl = $base;
        $this->deviceCode = $started['device_code'];
        $this->userCode = $started['user_code'];
        $this->verificationUri = $started['verification_uri'];
        $this->verificationUriComplete = $started['verification_uri_complete'];
        $this->pollInterval = max(2, (int) ($started['interval'] ?? 2));
        $this->status = 'polling';
    }

    public function pollDeviceFlow(): void
    {
        if ($this->status !== 'polling' || $this->deviceCode === null) {
            return;
        }

        try {
            $client = ProductionApiClient::unauthenticated($this->baseUrl);
            $result = $client->pollDeviceAuthorization($this->deviceCode);
        } catch (ProductionApiException $e) {
            $this->status = 'error';
            $this->errorMessage = $e->getMessage();

            return;
        } catch (\Throwable $e) {
            $this->status = 'error';
            $this->errorMessage = $e->getMessage();

            return;
        }

        $status = (string) ($result['status'] ?? '');

        if ($status === 'pending') {
            return;
        }

        if ($status === 'denied') {
            $this->status = 'denied';
            $this->errorMessage = __('Authorization was denied on production.');

            return;
        }

        if ($status === 'expired') {
            $this->status = 'expired';
            $this->errorMessage = __('That code expired. Start again.');

            return;
        }

        if ($status !== 'authorized' || empty($result['token'])) {
            $this->status = 'error';
            $this->errorMessage = __('Unexpected device-flow response.');

            return;
        }

        $this->persistConnection((string) $result['token']);
    }

    public function cancelDeviceFlow(): void
    {
        $this->deviceCode = null;
        $this->userCode = null;
        $this->verificationUri = null;
        $this->verificationUriComplete = null;
        $this->status = 'idle';
        $this->errorMessage = null;
    }

    protected function persistConnection(string $token): void
    {
        $user = Auth::user();
        if ($user === null) {
            return;
        }

        try {
            $client = new ProductionApiClient($this->baseUrl, $token);
            $account = $client->account();
        } catch (ProductionApiException $e) {
            $this->status = 'error';
            $this->errorMessage = $e->getMessage();

            return;
        }

        $org = $account['data']['organization'] ?? [];
        $remoteUser = $account['data']['user'] ?? [];

        ProductionDataConnection::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'base_url' => $this->baseUrl,
                'api_token' => $token,
                'remote_organization_id' => isset($org['id']) ? (string) $org['id'] : null,
                'remote_organization_name' => isset($org['name']) ? (string) $org['name'] : null,
                'remote_organization_slug' => isset($org['slug']) ? (string) $org['slug'] : null,
                'remote_user_email' => isset($remoteUser['email']) ? (string) $remoteUser['email'] : null,
                'remote_user_name' => isset($remoteUser['name']) ? (string) $remoteUser['name'] : null,
                'connected_at' => now(),
                'last_used_at' => now(),
            ],
        );
        ProductionDataMirror::forgetConnectionMemo((string) $user->id);

        $this->status = 'connected';
        $this->toastSuccess(__('Connected to production data.'));
        $this->redirect(route('live.sites.index'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.live.connect', [
            'defaultBaseUrl' => ProductionDataMirror::defaultBaseUrl(),
        ]);
    }
}

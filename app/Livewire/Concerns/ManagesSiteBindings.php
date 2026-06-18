<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Jobs\FixSiteBindingConnectivityJob;
use App\Jobs\InstallCacheServiceJob;
use App\Jobs\SendBindingTestEmailJob;
use App\Jobs\SwitchCacheServiceJob;
use App\Jobs\TestBroadcastingBindingJob;
use App\Jobs\ValidateBindingConnectivityJob;
use App\Jobs\ValidateSiteBindingsReachableJob;
use App\Models\AiCredential;
use App\Models\CaptchaCredential;
use App\Models\ErrorTrackingCredential;
use App\Models\LogDrainCredential;
use App\Models\MailCredential;
use App\Models\OauthCredential;
use App\Models\ObjectStorageCredential;
use App\Models\PaymentCredential;
use App\Models\ProviderCredential;
use App\Models\RealtimeApp;
use App\Models\SearchCredential;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerDatabase;
use App\Models\SiteBinding;
use App\Models\SmsCredential;
use App\Services\Deploy\DeploymentSecretInventory;
use App\Services\Deploy\SiteBindingManager;
use App\Support\Servers\CacheEngineAvailability;
use Illuminate\Support\Facades\Gate;

/**
 * Attach / provision / detach actions for a site's managed resource bindings,
 * surfaced on the Environment settings tab. State for the single shared modal
 * lives here; the per-type form fields are kept in one loose array so the
 * modal can render whichever shape the chosen type needs.
 */
trait ManagesSiteBindings
{
    use BuildsSiteBindingFormDefaults;
    use ManagesSiteBindingActions;
    use ManagesSiteBindingCredentials;
    use ManagesSiteBindingMail;
    use ManagesSiteBindingStorage;
    use VerifiesSiteBindings;

    /** database | scheduler | workers | redis | queue | storage */
    public ?string $fixBindingId = null;

    /** The in-flight fix run id — when set, the fix modal shows live progress in place. */
    public ?string $fixBindingRunId = null;

    public string $bindingModalType = '';

    /** attach | provision */
    public string $bindingModalMode = 'attach';

    /**
     * When set, the open binding modal is EDITING this specific binding row
     * rather than adding a new one. Only meaningful for multi-instance types
     * (storage), where one site holds several bindings of the same type.
     */
    public ?string $bindingModalBindingId = null;

    /** @var array<string, mixed> */
    public array $bindingForm = [];

    /** @var list<array{id: string, label: string}> */
    public array $bindingTargets = [];

    /** Recipient for the mail binding's "send test email" action. */
    public string $mailTestRecipient = '';


}

<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\Server;

/**
 * Attach / provision / detach actions for a site's managed resource bindings,
 * surfaced on the Environment settings tab. State for the single shared modal
 * lives here; the per-type form fields are kept in one loose array so the
 * modal can render whichever shape the chosen type needs.
 */
trait ManagesSiteBindings
{
    use BuildsSiteBindingFormDefaults;
    use ManagesBindingEnvMapping;
    use ManagesSiteBindingActions;
    use ManagesSiteBindingCloudflareEmail;
    use ManagesSiteBindingCredentials;
    use ManagesSiteBindingMail;
    use ManagesSiteBindingStorage;
    use VerifiesSiteBindings;

    /** database | scheduler | workers | redis | queue | storage */
    public ?string $fixBindingId = null;

    /** The in-flight fix run id — when set, the fix modal shows live progress in place. */
    public ?string $fixBindingRunId = null;

    /** Read-only connection-details modal payload for one binding (null = closed). */
    public ?array $bindingInfo = null;

    public string $bindingModalType = '';

    /** attach | provision | edit */
    public string $bindingModalMode = 'attach';

    /**
     * Summary of the binding being edited (placement, status, available
     * actions). Null unless {@see $bindingModalMode} is `edit`.
     *
     * @var array<string, mixed>|null
     */
    public ?array $bindingEdit = null;

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

    /**
     * Sizes available for a "dedicated database VM" placement, fetched per the
     * app server's provider/region when the database-provision modal opens.
     *
     * @var list<array{value: string, label: string}>
     */
    public array $dedicatedVmSizes = [];

    /** Last catalog failure for dedicated VM placements; shown on the disabled card. */
    public ?string $dedicatedVmSizeError = null;

    /** Shown after a connected-app .env paste fills matching fields. */
    public ?string $connectedAppPasteNote = null;

    /** Resolved IaaS region for dedicated VM create (after normalizing stale slugs). */
    public ?string $dedicatedVmRegion = null;

    /** App server region before remap, for the "same metro as sfo" note. */
    public ?string $dedicatedVmRequestedRegion = null;

    /** Recipient for the mail binding's "send test email" action. */
    public string $mailTestRecipient = '';

    /**
     * Organizations resolved from a pasted Lookout API token, so the Lookout
     * provision form can offer a picker instead of a raw ULID. Empty until the
     * operator loads them (falls back to a free-text organization id).
     *
     * @var list<array{id: string, name: string}>
     */
    public array $lookoutOrganizations = [];
}

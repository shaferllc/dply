<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Jobs\ApplySiteWebserverConfigJob;
use App\Jobs\SyncBasicAuthFromServerJob;
use App\Models\SiteAccessGate;
use App\Models\SiteAccessGatePassword;
use App\Models\SiteBasicAuthUser;
use App\Services\Sites\SiteAccessGateLoginLogReader;
use App\Services\Sites\SiteAccessGateService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteAccessGate
{
    public string $new_basic_auth_username = '';

    public string $new_basic_auth_password = '';

    public string $new_basic_auth_path = '/';

    public string $bulk_basic_auth_input = '';

    public string $bulk_basic_auth_path = '/';

    public string $access_gate_method = '';

    public string $form_gate_password = '';

    public string $new_form_gate_label = '';

    /** @var list<array{at: string, label: string, credential_id: string, hostname: string, ip: string|null, user_agent: string|null}> */
    public array $form_gate_login_log = [];

    public bool $form_gate_login_log_loaded = false;

    public function addBasicAuthUser(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->site->supportsBasicAuthProvisioning()) {
            $this->toastError(__('Basic authentication is not available for this site runtime.'));

            return;
        }

        app(SiteAccessGateService::class)->ensureBasicAuthMethod($this->site);
        $this->access_gate_method = SiteAccessGate::METHOD_BASIC_AUTH;

        $pathRules = ['required', 'string', 'max:512'];
        if (! $this->site->basicAuthSupportsPathPrefixes()) {
            $pathRules[] = Rule::in(['/', '']);
        }

        $validated = $this->validate([
            'new_basic_auth_username' => [
                'required',
                'string',
                'max:128',
                Rule::unique('site_basic_auth_users', 'username')
                    ->where(fn ($query) => $query
                        ->where('site_id', $this->site->id)
                        ->whereNull('pending_removal_at')),
            ],
            'new_basic_auth_password' => ['required', 'string', 'min:8', 'max:255'],
            'new_basic_auth_path' => $pathRules,
        ]);

        $path = SiteBasicAuthUser::normalizePath($validated['new_basic_auth_path'] ?? '/');
        if (! $this->site->basicAuthSupportsPathPrefixes() && $path !== '/') {
            $this->addError('new_basic_auth_path', __('Use path / for this site type.'));

            return;
        }

        if (! preg_match('#^(/|/[a-zA-Z0-9/_-]*)$#', $path)) {
            $this->addError('new_basic_auth_path', __('Enter a path like / or /wp-admin.'));

            return;
        }

        $username = trim($validated['new_basic_auth_username']);
        $passwordHash = $this->site->hashBasicAuthPassword($validated['new_basic_auth_password']);

        $pendingRow = $this->site->basicAuthUsers()
            ->where('username', $username)
            ->whereNotNull('pending_removal_at')
            ->first();

        if ($pendingRow !== null) {
            $pendingRow->forceFill([
                'password_hash' => $passwordHash,
                'path' => $path,
                'pending_removal_at' => null,
            ])->save();
            $savedMessage = __('Basic authentication user restored.');
        } else {
            SiteBasicAuthUser::query()->create([
                'site_id' => $this->site->id,
                'username' => $username,
                'password_hash' => $passwordHash,
                'path' => $path,
                'sort_order' => (int) ($this->site->basicAuthUsers()->max('sort_order') ?? 0) + 1,
            ]);
            $savedMessage = __('Basic authentication user saved.');
        }

        $this->new_basic_auth_username = '';
        $this->new_basic_auth_password = '';
        $this->new_basic_auth_path = '/';
        $this->site->load('basicAuthUsers');
        $this->dispatch('close-modal', 'add-basic-auth-modal');
        $this->finalizeRoutingMutation($savedMessage, __('Adding credential to :host …', ['host' => $this->site->server?->name ?? $this->site->name]));
    }

    /**
     * Open a confirm-modal before removing a basic-auth credential. The actual
     * mark-and-apply happens in {@see removeBasicAuthUser()} only after the
     * operator clicks through the modal.
     */
    public function confirmRemoveBasicAuthUser(string $userId): void
    {
        $this->authorize('update', $this->site);

        if (! $this->site->supportsBasicAuthProvisioning()) {
            return;
        }

        $user = $this->site->basicAuthUsers()->findOrFail($userId);

        $this->openConfirmActionModal(
            'removeBasicAuthUser',
            [$userId],
            __('Remove credential?'),
            __('Stops :username from passing the basic-auth gate. The credential is marked Removing while the webserver config rewrites; we hard-delete the row only after the apply succeeds.', ['username' => $user->username]),
            __('Remove credential'),
            true,
        );
    }

    public function removeBasicAuthUser(string $userId): void
    {
        $this->authorize('update', $this->site);

        if (! $this->site->supportsBasicAuthProvisioning()) {
            return;
        }

        // Stamp pending_removal_at instead of hard-deleting. The htpasswd-sync
        // step in the apply skips pending rows, so this row stops authenticating
        // the moment the apply succeeds. ApplySiteWebserverConfigJob hard-deletes
        // the row after a clean run — that way the UI never claims the credential
        // is gone before the webserver actually agrees.
        $user = $this->site->basicAuthUsers()->findOrFail($userId);
        if ($user->pending_removal_at === null) {
            $user->forceFill(['pending_removal_at' => now()])->save();
        }

        $this->site->load('basicAuthUsers');
        $this->finalizeRoutingMutation(
            __('Basic auth credential marked for removal — track the apply in the banner.'),
            __('Removing credential from :host …', ['host' => $this->site->server?->name ?? $this->site->name]),
        );
    }

    public function generateBasicAuthPassword(): void
    {
        $this->authorize('update', $this->site);
        $this->new_basic_auth_password = Str::password(20);
    }

    public function generateFormGatePassword(): void
    {
        $this->authorize('update', $this->site);
        $this->form_gate_password = Str::password(20);
    }

    public function selectAccessGateMethod(string $method): void
    {
        $this->authorize('update', $this->site);

        if (! $this->site->supportsAccessGateProvisioning()) {
            return;
        }

        if (! in_array($method, [
            SiteAccessGate::METHOD_OFF,
            SiteAccessGate::METHOD_BASIC_AUTH,
            SiteAccessGate::METHOD_FORM_PASSWORD,
        ], true)) {
            return;
        }

        if ($method === SiteAccessGate::METHOD_FORM_PASSWORD && ! $this->site->webserverSupportsFormPasswordGate()) {
            $this->toastError(__('Password gate is not available for OpenLiteSpeed in this release.'));

            return;
        }

        $live = $this->site->resolvedAccessGateMethod();
        if ($method === $live && $method !== SiteAccessGate::METHOD_FORM_PASSWORD) {
            $this->access_gate_method = $method;

            return;
        }

        if ($method === SiteAccessGate::METHOD_FORM_PASSWORD && $live !== SiteAccessGate::METHOD_FORM_PASSWORD) {
            if ($live === SiteAccessGate::METHOD_BASIC_AUTH && $this->site->enforceableBasicAuthUsers()->isNotEmpty()) {
                $this->openConfirmActionModal(
                    'prepareFormPasswordGate',
                    [],
                    __('Switch to password gate?'),
                    __('HTTP basic auth credentials will be removed on the next webserver apply. Enter a new shared password below, then save.'),
                    __('Switch method'),
                    true,
                );

                return;
            }

            $this->access_gate_method = SiteAccessGate::METHOD_FORM_PASSWORD;

            return;
        }

        if ($live === SiteAccessGate::METHOD_FORM_PASSWORD && $method !== SiteAccessGate::METHOD_FORM_PASSWORD) {
            $this->openConfirmActionModal(
                'applyAccessGateMethod',
                [$method],
                __('Switch access method?'),
                __('The password gate will be removed on the next webserver apply.'),
                __('Switch method'),
                $method === SiteAccessGate::METHOD_OFF,
            );

            return;
        }

        if ($live === SiteAccessGate::METHOD_BASIC_AUTH && $this->site->enforceableBasicAuthUsers()->isNotEmpty() && $method === SiteAccessGate::METHOD_OFF) {
            $this->openConfirmActionModal(
                'applyAccessGateMethod',
                [$method],
                __('Turn off access protection?'),
                __('All basic auth credentials will be removed on the next webserver apply.'),
                __('Turn off protection'),
                true,
            );

            return;
        }

        $this->applyAccessGateMethod($method);
    }

    public function prepareFormPasswordGate(): void
    {
        $this->authorize('update', $this->site);
        app(SiteAccessGateService::class)->markAllBasicAuthUsersForRemoval($this->site);
        $this->access_gate_method = SiteAccessGate::METHOD_FORM_PASSWORD;
        $this->site->load('basicAuthUsers');
    }

    public function applyAccessGateMethod(string $method): void
    {
        $this->authorize('update', $this->site);

        if (! $this->site->supportsAccessGateProvisioning()) {
            return;
        }

        $service = app(SiteAccessGateService::class);

        if ($method === SiteAccessGate::METHOD_OFF) {
            $service->markAllBasicAuthUsersForRemoval($this->site);
            $service->disable($this->site);
            $this->access_gate_method = SiteAccessGate::METHOD_OFF;
            $this->site->load(['accessGate', 'basicAuthUsers']);
            $this->finalizeRoutingMutation(__('Access protection turned off.'));

            return;
        }

        if ($method === SiteAccessGate::METHOD_BASIC_AUTH) {
            $wasForm = $this->site->usesFormPasswordGate();
            app(SiteAccessGateService::class)->markAllFormGatePasswordsForRemoval($this->site);
            $gate = SiteAccessGate::query()->firstOrNew(['site_id' => $this->site->id]);
            if (! $gate->exists) {
                $gate->cookie_secret = Str::random(48);
            }
            $gate->method = SiteAccessGate::METHOD_BASIC_AUTH;
            $gate->password_salt = null;
            $gate->password_verifier = null;
            $gate->save();
            $this->access_gate_method = SiteAccessGate::METHOD_BASIC_AUTH;
            $this->site->load(['accessGate', 'basicAuthUsers']);

            if ($wasForm) {
                $this->finalizeRoutingMutation(__('Switched to HTTP basic auth.'));
            }

            return;
        }

        $this->access_gate_method = SiteAccessGate::METHOD_FORM_PASSWORD;
    }

    public function saveFormGatePassword(): void
    {
        $this->addFormGatePassword();
    }

    public function addFormGatePassword(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->site->supportsAccessGateProvisioning()) {
            $this->toastError(__('Access protection is not available for this site runtime.'));

            return;
        }

        if (! $this->site->webserverSupportsFormPasswordGate()) {
            $this->toastError(__('Password gate is not available for OpenLiteSpeed in this release.'));

            return;
        }

        $validated = $this->validate([
            'new_form_gate_label' => ['required', 'string', 'max:64'],
            'form_gate_password' => ['required', 'string', 'min:8', 'max:255'],
        ], [], [
            'new_form_gate_label' => __('label'),
            'form_gate_password' => __('password'),
        ]);

        app(SiteAccessGateService::class)->addFormGatePassword(
            $this->site,
            $validated['new_form_gate_label'],
            $validated['form_gate_password'],
        );

        $this->new_form_gate_label = '';
        $this->form_gate_password = '';
        $this->access_gate_method = SiteAccessGate::METHOD_FORM_PASSWORD;
        $this->form_gate_login_log_loaded = false;
        $this->dispatch('close-modal', 'add-form-gate-modal');
        $this->site->load(['accessGate', 'accessGatePasswords', 'basicAuthUsers']);
        $this->finalizeRoutingMutation(
            __('Password gate credential saved.'),
            __('Applying password gate on :host …', ['host' => $this->site->server?->name ?? $this->site->name]),
        );
    }

    public function loadFormGateLoginLog(): void
    {
        $this->authorize('view', $this->site);

        if (! $this->site->usesFormPasswordGate()) {
            $this->form_gate_login_log = [];
            $this->form_gate_login_log_loaded = true;

            return;
        }

        $this->form_gate_login_log = app(SiteAccessGateLoginLogReader::class)->recent($this->site);
        $this->form_gate_login_log_loaded = true;
    }

    public function confirmRemoveFormGatePassword(string $passwordId): void
    {
        $this->authorize('update', $this->site);

        $row = SiteAccessGatePassword::query()
            ->where('site_id', $this->site->id)
            ->where('id', $passwordId)
            ->first();

        if ($row === null || $row->isPendingRemoval()) {
            return;
        }

        $this->openConfirmActionModal(
            'removeFormGatePassword',
            [$passwordId],
            __('Remove password gate credential?'),
            __(':label will stop working after the next webserver apply.', ['label' => $row->label]),
            __('Remove credential'),
            true,
        );
    }

    public function removeFormGatePassword(string $passwordId): void
    {
        $this->authorize('update', $this->site);

        app(SiteAccessGateService::class)->markFormGatePasswordForRemoval($this->site, $passwordId);
        $this->site->load(['accessGate', 'accessGatePasswords']);

        if ($this->site->enforceableAccessGatePasswords()->isEmpty()) {
            $gate = $this->site->accessGate;
            if ($gate !== null) {
                $gate->method = SiteAccessGate::METHOD_FORM_PASSWORD;
                $gate->save();
            }
        }

        $this->finalizeRoutingMutation(__('Password gate credential marked for removal.'));
    }

    public function disableFormGatePassword(): void
    {
        $this->authorize('update', $this->site);

        $this->openConfirmActionModal(
            'applyAccessGateMethod',
            [SiteAccessGate::METHOD_OFF],
            __('Remove password gate?'),
            __('Visitors will reach the site without the login form after the next webserver apply.'),
            __('Remove gate'),
            true,
        );
    }

    /**
     * Dispatches a backgrounded job that walks the server, finds every .htpasswd
     * inside the site repo, and imports the user entries Dply doesn't already
     * track. Progress streams into a console_actions row whose banner is mounted
     * at the top of the settings page, so the operator can watch the scan happen
     * line-by-line instead of seeing a synchronous toast that hides the work.
     */
    public function syncBasicAuthFromServer(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->site->supportsBasicAuthProvisioning()) {
            $this->toastError(__('Basic authentication is not available for this site runtime.'));

            return;
        }

        // Seed a queued ConsoleAction row BEFORE dispatch so the page-top banner
        // shows immediately on this re-render. Without this, the row only exists
        // once the worker calls beginConsoleAction(), which is async — the
        // banner reads from the DB on parent render and would stay empty until
        // the user navigated or another action triggered a re-render.
        $run = $this->seedQueuedConsoleAction('basic_auth_sync');

        SyncBasicAuthFromServerJob::dispatch(
            $this->site->id,
            (string) (auth()->id() ?? ''),
            (string) $run->id,
        );

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction(
            $run,
            __('Basic-auth sync finished.'),
            __('Basic-auth sync did not finish.'),
        );
        $this->toastConsoleActionQueued();
    }

    /**
     * @param  string  $customPassword  Operator-supplied plaintext from the rotate
     *                                  dialog. The dialog generates a random default and lets the operator copy
     *                                  it before submit, so by the time we hit this method the value is always
     *                                  present. Validated against the same min:8/max:255 rules used by the
     *                                  add-credential form.
     */
    public function rotateBasicAuthPassword(string $userId, string $customPassword): void
    {
        $this->authorize('update', $this->site);

        if (! $this->site->supportsBasicAuthProvisioning()) {
            $this->toastError(__('Basic authentication is not available for this site runtime.'));

            return;
        }

        /** @var SiteBasicAuthUser $user */
        $user = $this->site->basicAuthUsers()->findOrFail($userId);

        $validator = Validator::make(
            ['password' => $customPassword],
            ['password' => ['required', 'string', 'min:8', 'max:255']],
        );
        if ($validator->fails()) {
            $this->toastError($validator->errors()->first('password') ?: __('Password must be 8–255 characters.'));

            return;
        }

        $user->password_hash = $this->site->hashBasicAuthPassword($customPassword);
        $user->save();

        $this->site->load('basicAuthUsers');
        $this->finalizeRoutingMutation(
            __('Password rotated.'),
            __('Rotating credential password on :host …', ['host' => $this->site->server?->name ?? $this->site->name]),
        );
    }

    public function bulkImportBasicAuth(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->site->supportsBasicAuthProvisioning()) {
            $this->toastError(__('Basic authentication is not available for this site runtime.'));

            return;
        }

        $path = SiteBasicAuthUser::normalizePath($this->bulk_basic_auth_path ?: '/');
        if (! $this->site->basicAuthSupportsPathPrefixes() && $path !== '/') {
            $this->addError('bulk_basic_auth_path', __('Use path / for this site type.'));

            return;
        }
        if (! preg_match('#^(/|/[a-zA-Z0-9/_-]*)$#', $path)) {
            $this->addError('bulk_basic_auth_path', __('Enter a path like / or /wp-admin.'));

            return;
        }

        $raw = (string) $this->bulk_basic_auth_input;
        if (trim($raw) === '') {
            $this->addError('bulk_basic_auth_input', __('Paste at least one user:password line.'));

            return;
        }

        $existing = $this->site->basicAuthUsers()->notPendingRemoval()->pluck('username')->all();
        $seen = [];
        $created = 0;
        $skipped = 0;
        $invalid = 0;
        $sortBase = (int) ($this->site->basicAuthUsers()->max('sort_order') ?? 0);

        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Split on the first colon — htpasswd-style lines for bcrypt/apr1 contain `$` chars
            // and additional colons in some encodings, so we never split more than once.
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0 || $colon === strlen($line) - 1) {
                $invalid++;

                continue;
            }
            $username = trim(substr($line, 0, $colon));
            $secret = substr($line, $colon + 1);

            if ($username === '' || strlen($username) > 128 || ! preg_match('/^[A-Za-z0-9._@\-]+$/', $username)) {
                $invalid++;

                continue;
            }
            if (in_array($username, $seen, true)) {
                $skipped++;

                continue;
            }

            // Accept already-hashed entries (bcrypt $2y / apr1 $apr1$ / sha $5$/$6$) verbatim;
            // otherwise treat the secret as plaintext and bcrypt-hash it server-side.
            $alreadyHashed = (bool) preg_match('/^\$(2[aby]|apr1|5|6)\$/', $secret);
            if (! $alreadyHashed && (strlen($secret) < 8 || strlen($secret) > 255)) {
                $invalid++;

                continue;
            }
            $hash = $alreadyHashed ? $secret : $this->site->hashBasicAuthPassword($secret);

            $pendingRow = $this->site->basicAuthUsers()
                ->where('username', $username)
                ->whereNotNull('pending_removal_at')
                ->first();

            if ($pendingRow !== null) {
                $pendingRow->forceFill([
                    'password_hash' => $hash,
                    'path' => $path,
                    'pending_removal_at' => null,
                ])->save();
                $seen[] = $username;
                $created++;

                continue;
            }

            if (in_array($username, $existing, true)) {
                $skipped++;

                continue;
            }

            SiteBasicAuthUser::query()->create([
                'site_id' => $this->site->id,
                'username' => $username,
                'password_hash' => $hash,
                'path' => $path,
                'sort_order' => ++$sortBase,
            ]);
            $seen[] = $username;
            $existing[] = $username;
            $created++;
        }

        if ($created === 0) {
            $this->addError('bulk_basic_auth_input', __('No valid user:password lines were found.'));

            return;
        }

        $this->bulk_basic_auth_input = '';
        $this->bulk_basic_auth_path = '/';
        $this->site->load('basicAuthUsers');
        $this->dispatch('close-modal', 'add-basic-auth-modal');

        $message = trans_choice(
            '{1} :count user imported.|[2,*] :count users imported.',
            $created,
            ['count' => $created],
        );
        if ($skipped > 0 || $invalid > 0) {
            $detail = [];
            if ($skipped > 0) {
                $detail[] = trans_choice('{1} :count duplicate skipped|[2,*] :count duplicates skipped', $skipped, ['count' => $skipped]);
            }
            if ($invalid > 0) {
                $detail[] = trans_choice('{1} :count invalid line|[2,*] :count invalid lines', $invalid, ['count' => $invalid]);
            }
            $message .= ' ('.implode(', ', $detail).')';
        }

        $this->finalizeRoutingMutation(
            $message,
            __('Importing credentials to :host …', ['host' => $this->site->server?->name ?? $this->site->name]),
        );
    }
}

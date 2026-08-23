<?php

declare(strict_types=1);

namespace App\Http\Controllers\Credentials;

use App\Http\Controllers\Controller;
use App\Models\BackupConfiguration;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * "Connect Dropbox" / "Connect Google Drive" — one click instead of a console
 * round trip.
 *
 * Mirrors {@see ProviderOAuthController}'s DigitalOcean flow, but produces a
 * {@see BackupConfiguration} rather than a ProviderCredential, because a backup
 * destination is a different shape (named, many per provider).
 *
 * The whole point is `token_access_type=offline`: without it Dropbox returns
 * only a ~4-hour access token, and a schedule pointed at that stops working
 * overnight with no error to look at. This flow always stores a refresh token.
 *
 * Requires a Dropbox app registered by whoever runs this deployment. When it
 * isn't configured the button hides itself and the manual fields remain — a
 * self-hoster is not blocked on us.
 */
class BackupStorageOAuthController extends Controller
{
    /** OAuth state is single-use and short-lived; 15 minutes is plenty to approve a dialog. */
    private const STATE_TTL_SECONDS = 900;

    public static function dropboxConfigured(): bool
    {
        return self::configured('dropbox');
    }

    public static function googleDriveConfigured(): bool
    {
        return self::configured('google_drive');
    }

    private static function configured(string $service): bool
    {
        $id = config("services.{$service}.client_id");
        $secret = config("services.{$service}.client_secret");

        return is_string($id) && $id !== '' && is_string($secret) && $secret !== '';
    }

    public function redirectGoogleDrive(Request $request): RedirectResponse
    {
        if (! self::googleDriveConfigured()) {
            return $this->back(null, __('Google Drive sign-in is not configured on this deployment. Add a client id, secret and refresh token manually instead.'));
        }

        $this->authorize('create', BackupConfiguration::class);

        $user = $request->user();
        $org = $user?->currentOrganization();
        if (! $org instanceof Organization) {
            return redirect()->route('organizations.index')
                ->with('error', __('Select an organization before connecting Google Drive.'));
        }

        if ($org->userIsDeployer($user)) {
            abort(403);
        }

        $destinationId = $this->reconnectId($request, $org, BackupConfiguration::PROVIDER_GOOGLE_DRIVE);

        $nonce = Str::random(40);
        $request->session()->put($this->stateKey($nonce), [
            'user_id' => (string) $user->id,
            'organization_id' => (string) $org->id,
            'destination_id' => $destinationId,
            'provider' => BackupConfiguration::PROVIDER_GOOGLE_DRIVE,
            'issued_at' => now()->timestamp,
        ]);

        $query = http_build_query([
            'client_id' => config('services.google_drive.client_id'),
            'redirect_uri' => $this->googleRedirectUri(),
            'response_type' => 'code',
            // drive.file scopes dply to files it creates itself — it cannot read
            // the rest of the customer's Drive, which is the right blast radius
            // for a backup target.
            'scope' => 'https://www.googleapis.com/auth/drive.file',
            // Both are required for a refresh token: offline alone returns one
            // only on the FIRST ever consent, so a re-connect would silently get
            // none and the destination would die within the hour.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $nonce,
        ]);

        return redirect('https://accounts.google.com/o/oauth2/v2/auth?'.$query);
    }

    public function callbackGoogleDrive(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            return $this->back(null, $request->string('error_description')->toString()
                ?: $request->string('error')->toString()
                ?: __('Authorization was denied or failed.'));
        }

        $request->validate(['code' => 'required|string', 'state' => 'required|string|size:40']);

        $payload = $request->session()->pull($this->stateKey($request->string('state')->toString()));
        if (! is_array($payload)) {
            return $this->back(null, __('Invalid or expired sign-in state. Please try again.'));
        }

        if (now()->timestamp - (int) ($payload['issued_at'] ?? 0) > self::STATE_TTL_SECONDS) {
            return $this->back(null, __('That sign-in link expired. Please try again.'));
        }

        $user = $request->user();
        if (! $user || (string) $user->id !== (string) ($payload['user_id'] ?? '')) {
            return redirect()->route('login')
                ->with('error', __('Your session changed during sign-in. Please sign in and connect again.'));
        }

        $org = Organization::query()->find($payload['organization_id'] ?? null);
        if (! $org instanceof Organization || ! $org->hasMember($user) || $org->userIsDeployer($user)) {
            return $this->back(null, __('You cannot add a destination for that organization.'));
        }

        $this->authorize('create', BackupConfiguration::class);

        $response = Http::asForm()->acceptJson()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'authorization_code',
            'code' => $request->string('code')->toString(),
            'client_id' => config('services.google_drive.client_id'),
            'client_secret' => config('services.google_drive.client_secret'),
            'redirect_uri' => $this->googleRedirectUri(),
        ]);

        if (! $response->successful()) {
            $detail = $response->json('error_description') ?? $response->json('error');

            return $this->back($org, __('Could not complete Google Drive sign-in: :detail', [
                'detail' => is_string($detail) && $detail !== '' ? $detail : 'HTTP '.$response->status(),
            ]));
        }

        $refreshToken = (string) ($response->json('refresh_token') ?? '');
        if ($refreshToken === '') {
            return $this->back($org, __('Google returned no refresh token, so scheduled dumps could not keep working. Remove dply from your Google account\'s third-party access and try again.'));
        }

        $config = [
            'client_id' => (string) config('services.google_drive.client_id'),
            'client_secret' => (string) config('services.google_drive.client_secret'),
            'refresh_token' => $refreshToken,
            'folder_id' => '',
        ];

        $existing = $this->reconnectTarget($org, $payload['destination_id'] ?? null, BackupConfiguration::PROVIDER_GOOGLE_DRIVE);

        if ($existing instanceof BackupConfiguration) {
            $config['folder_id'] = (string) (($existing->config ?? [])['folder_id'] ?? '');
            $config['compress'] = (bool) (($existing->config ?? [])['compress'] ?? false);
            $existing->update(['config' => $config]);

            audit_log($org, $user, 'backup.destination.updated', $existing, null, [
                'name' => $existing->name,
                'provider' => BackupConfiguration::PROVIDER_GOOGLE_DRIVE,
                'auth' => 'oauth',
            ]);

            return $this->back($org)->with('success', __('Google Drive reconnected — :name now uses a refresh token that will not expire.', [
                'name' => $existing->name,
            ]));
        }

        $destination = $org->backupConfigurations()->create([
            'name' => $this->uniqueName($org, BackupConfiguration::PROVIDER_GOOGLE_DRIVE, 'Google Drive'),
            'provider' => BackupConfiguration::PROVIDER_GOOGLE_DRIVE,
            'config' => $config,
            'created_by_user_id' => $user->id,
        ]);

        audit_log($org, $user, 'backup.destination.created', $destination, null, [
            'name' => $destination->name,
            'provider' => BackupConfiguration::PROVIDER_GOOGLE_DRIVE,
            'auth' => 'oauth',
        ]);

        return $this->back($org)->with('success', __('Google Drive connected — :name is ready to receive backups.', [
            'name' => $destination->name,
        ]));
    }

    private function googleRedirectUri(): string
    {
        $configured = config('services.google_drive.redirect');

        return is_string($configured) && $configured !== ''
            ? $configured
            : route('credentials.oauth.google-drive.callback', [], true);
    }

    /** Validate an optional ?destination= against the org before trusting it. */
    private function reconnectId(Request $request, Organization $org, string $provider): string
    {
        $id = $request->string('destination')->toString();
        if ($id === '') {
            return '';
        }

        return $org->backupConfigurations()->where('provider', $provider)->whereKey($id)->exists() ? $id : '';
    }

    public function redirectDropbox(Request $request): RedirectResponse
    {
        if (! self::dropboxConfigured()) {
            return $this->back(null, __('Dropbox sign-in is not configured on this deployment. Add an app key and refresh token manually instead.'));
        }

        $this->authorize('create', BackupConfiguration::class);

        $user = $request->user();
        $org = $user?->currentOrganization();
        if (! $org instanceof Organization) {
            return redirect()
                ->route('organizations.index')
                ->with('error', __('Select an organization before connecting Dropbox.'));
        }

        if ($org->userIsDeployer($user)) {
            abort(403);
        }

        // Reconnecting an existing destination swaps its credentials in place —
        // otherwise fixing an expiring token would litter the org with
        // duplicates and leave every schedule pointed at the dead one.
        $destinationId = $request->string('destination')->toString();
        if ($destinationId !== '') {
            $exists = $org->backupConfigurations()
                ->where('provider', BackupConfiguration::PROVIDER_DROPBOX)
                ->whereKey($destinationId)
                ->exists();

            if (! $exists) {
                $destinationId = '';
            }
        }

        $nonce = Str::random(40);
        $request->session()->put($this->stateKey($nonce), [
            'user_id' => (string) $user->id,
            'organization_id' => (string) $org->id,
            'destination_id' => $destinationId,
            'issued_at' => now()->timestamp,
        ]);

        $query = http_build_query([
            'client_id' => config('services.dropbox.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            // Without offline access Dropbox issues no refresh token at all.
            'token_access_type' => 'offline',
            'scope' => 'files.content.write files.content.read',
            'state' => $nonce,
        ]);

        return redirect('https://www.dropbox.com/oauth2/authorize?'.$query);
    }

    public function callbackDropbox(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            return $this->back(null, $request->string('error_description')->toString()
                ?: $request->string('error')->toString()
                ?: __('Authorization was denied or failed.'));
        }

        $request->validate([
            'code' => 'required|string',
            'state' => 'required|string|size:40',
        ]);

        $payload = $request->session()->pull($this->stateKey($request->string('state')->toString()));
        if (! is_array($payload)) {
            return $this->back(null, __('Invalid or expired sign-in state. Please try again.'));
        }

        $issuedAt = (int) ($payload['issued_at'] ?? 0);
        if (now()->timestamp - $issuedAt > self::STATE_TTL_SECONDS) {
            return $this->back(null, __('That sign-in link expired. Please try again.'));
        }

        $user = $request->user();
        if (! $user || (string) $user->id !== (string) ($payload['user_id'] ?? '')) {
            return redirect()->route('login')
                ->with('error', __('Your session changed during sign-in. Please sign in and connect again.'));
        }

        $org = Organization::query()->find($payload['organization_id'] ?? null);
        if (! $org instanceof Organization || ! $org->hasMember($user) || $org->userIsDeployer($user)) {
            return $this->back(null, __('You cannot add a destination for that organization.'));
        }

        $this->authorize('create', BackupConfiguration::class);

        $response = Http::asForm()
            ->acceptJson()
            ->post('https://api.dropbox.com/oauth2/token', [
                'grant_type' => 'authorization_code',
                'code' => $request->string('code')->toString(),
                'client_id' => config('services.dropbox.client_id'),
                'client_secret' => config('services.dropbox.client_secret'),
                'redirect_uri' => $this->redirectUri(),
            ]);

        if (! $response->successful()) {
            $detail = $response->json('error_description') ?? $response->json('error_summary');

            return $this->back($org, __('Could not complete Dropbox sign-in: :detail', [
                'detail' => is_string($detail) && $detail !== '' ? $detail : 'HTTP '.$response->status(),
            ]));
        }

        $refreshToken = (string) ($response->json('refresh_token') ?? '');
        if ($refreshToken === '') {
            // Almost always a missing token_access_type=offline on the authorize
            // call. Saying so beats "something went wrong".
            return $this->back($org, __('Dropbox returned no refresh token, so scheduled dumps could not keep working. Try again, or add credentials manually.'));
        }

        $config = [
            'app_key' => (string) config('services.dropbox.client_id'),
            'app_secret' => (string) config('services.dropbox.client_secret'),
            'refresh_token' => $refreshToken,
            // Deliberately blanked: the short-lived token from this exchange
            // would be stale within hours, and the resolver mints a fresh one
            // per run from the refresh token anyway. Leaving it set would also
            // keep the "expires" warning showing on a now-durable destination.
            'access_token' => '',
            'path' => '',
        ];

        $existing = $this->reconnectTarget($org, $payload['destination_id'] ?? null);

        if ($existing instanceof BackupConfiguration) {
            // Keep the operator's own folder choice — they are replacing
            // credentials, not re-configuring where dumps land.
            $config['path'] = (string) (($existing->config ?? [])['path'] ?? '');
            $existing->update(['config' => $config]);

            audit_log($org, $user, 'backup.destination.updated', $existing, null, [
                'name' => $existing->name,
                'provider' => BackupConfiguration::PROVIDER_DROPBOX,
                'auth' => 'oauth',
            ]);

            return $this->back($org)->with('success', __('Dropbox reconnected — :name now uses a refresh token that will not expire.', [
                'name' => $existing->name,
            ]));
        }

        $destination = $org->backupConfigurations()->create([
            'name' => $this->uniqueName($org),
            'provider' => BackupConfiguration::PROVIDER_DROPBOX,
            'config' => $config,
            'created_by_user_id' => $user->id,
        ]);

        audit_log($org, $user, 'backup.destination.created', $destination, null, [
            'name' => $destination->name,
            'provider' => BackupConfiguration::PROVIDER_DROPBOX,
            'auth' => 'oauth',
        ]);

        return $this->back($org)->with('success', __('Dropbox connected — :name is ready to receive backups.', [
            'name' => $destination->name,
        ]));
    }

    /** The destination being reconnected, re-checked at callback time. */
    private function reconnectTarget(Organization $org, mixed $destinationId, string $provider = BackupConfiguration::PROVIDER_DROPBOX): ?BackupConfiguration
    {
        if (! is_string($destinationId) || $destinationId === '') {
            return null;
        }

        return $org->backupConfigurations()
            ->where('provider', $provider)
            ->whereKey($destinationId)
            ->first();
    }

    /** Keep repeat connections distinguishable rather than three rows all called "Dropbox". */
    private function uniqueName(
        Organization $org,
        string $provider = BackupConfiguration::PROVIDER_DROPBOX,
        string $label = 'Dropbox',
    ): string {
        $existing = $org->backupConfigurations()->where('provider', $provider)->count();

        return $existing === 0 ? $label : $label.' '.($existing + 1);
    }

    private function redirectUri(): string
    {
        $configured = config('services.dropbox.redirect');

        return is_string($configured) && $configured !== ''
            ? $configured
            : route('credentials.oauth.dropbox.callback', [], true);
    }

    /**
     * Return to the credentials page on whatever host the callback arrived at.
     *
     * Deliberately a PATH, not a route() absolute URL. Providers often refuse a
     * `.test` redirect URI, so local setups register `http://localhost:8000`
     * instead — and an absolute APP_URL redirect would bounce the operator to a
     * different host, where the session (and therefore the flash message they
     * need to read) does not exist.
     */
    private function back(?Organization $org, ?string $error = null): RedirectResponse
    {
        $org ??= request()->user()?->currentOrganization();

        $path = $org instanceof Organization
            ? '/organizations/'.$org->id.'/credentials?filter=storage'
            : '/credentials';

        $redirect = redirect()->to($path);

        return $error === null ? $redirect : $redirect->with('error', $error);
    }

    private function stateKey(string $nonce): string
    {
        return 'backup_storage_oauth_dropbox_'.$nonce;
    }
}

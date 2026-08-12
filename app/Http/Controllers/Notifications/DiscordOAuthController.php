<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Models\DiscordInstallation;
use App\Models\DiscordPermissions;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Modules\Notifications\Services\DiscordGuildClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * "Add to Discord" — invite the dply bot to a server once, then pick channels
 * from a dropdown.
 *
 * Structurally a twin of {@see SlackOAuthController} (nonce in session, short
 * TTL, actor and permission re-verified at callback), with one real difference:
 * the code exchange yields a **guild**, not a credential. Discord's bot token is
 * application-wide and lives in config, so what gets stored here is only which
 * server admitted the bot.
 *
 * That makes the bot token a hard prerequisite — an operator can complete this
 * OAuth flow and still have a non-working channel if the deployment never set
 * DISCORD_BOT_TOKEN, so `configured()` insists on all three values before the
 * button appears at all.
 */
class DiscordOAuthController extends Controller
{
    /** OAuth state is single-use and short-lived; 15 minutes is plenty to approve a dialog. */
    private const STATE_TTL_SECONDS = 900;

    /**
     * `bot` is the only scope needed to join a guild and post. `applications.commands`
     * is deliberately omitted — dply registers no slash commands, and asking for
     * it would widen the consent screen for nothing.
     */
    public const SCOPES = 'bot';

    public static function configured(): bool
    {
        $id = config('services.discord.client_id');
        $secret = config('services.discord.client_secret');

        return is_string($id) && $id !== ''
            && is_string($secret) && $secret !== ''
            // Without the bot token the flow would "succeed" into a channel that
            // can never deliver, so treat it as part of being configured.
            && DiscordGuildClient::botConfigured();
    }

    public function redirect(Request $request): RedirectResponse
    {
        $returnTo = $this->sanitizeReturnTo($request->query('return_to'));

        if (! self::configured()) {
            return $this->back($returnTo, __('Discord sign-in is not configured on this deployment. Add a webhook URL manually instead.'));
        }

        $user = $request->user();
        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        $owner = $this->resolveOwner($request, $user);
        if (! $owner instanceof Model) {
            return $this->back($returnTo, __('Could not work out which account the Discord server should belong to.'));
        }

        if (! Gate::allows('manageNotificationChannels', $owner)) {
            abort(403);
        }

        $nonce = Str::random(40);
        $request->session()->put($this->stateKey($nonce), [
            'user_id' => (string) $user->id,
            'owner_type' => $owner::class,
            'owner_id' => (string) $owner->getKey(),
            'return_to' => $returnTo,
            'issued_at' => now()->timestamp,
        ]);

        $query = http_build_query([
            'client_id' => config('services.discord.client_id'),
            'scope' => self::SCOPES,
            'permissions' => DiscordPermissions::REQUIRED,
            // response_type=code (rather than Discord's older implicit bot-add)
            // is what makes the exchange return the guild object, so the install
            // can be recorded instead of guessed at.
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'state' => $nonce,
        ]);

        return redirect('https://discord.com/oauth2/authorize?'.$query);
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            return $this->back(null, $request->string('error')->toString() === 'access_denied'
                ? __('Discord authorization was cancelled.')
                : __('Discord authorization failed: :error', ['error' => $request->string('error')->toString()]));
        }

        $request->validate([
            'code' => 'required|string',
            'state' => 'required|string|size:40',
        ]);

        $payload = $request->session()->pull($this->stateKey($request->string('state')->toString()));
        if (! is_array($payload)) {
            return $this->back(null, __('Invalid or expired sign-in state. Please try again.'));
        }

        $returnTo = $this->sanitizeReturnTo($payload['return_to'] ?? null);

        if (now()->timestamp - (int) ($payload['issued_at'] ?? 0) > self::STATE_TTL_SECONDS) {
            return $this->back($returnTo, __('That sign-in link expired. Please try again.'));
        }

        $user = $request->user();
        if (! $user instanceof User || (string) $user->id !== (string) ($payload['user_id'] ?? '')) {
            return redirect()->route('login')
                ->with('error', __('Your session changed during sign-in. Please sign in and connect again.'));
        }

        $owner = $this->ownerFromPayload($payload);
        if (! $owner instanceof Model || ! Gate::allows('manageNotificationChannels', $owner)) {
            return $this->back($returnTo, __('You cannot connect a Discord server for that account.'));
        }

        $response = Http::asForm()->acceptJson()->post('https://discord.com/api/v10/oauth2/token', [
            'client_id' => config('services.discord.client_id'),
            'client_secret' => config('services.discord.client_secret'),
            'grant_type' => 'authorization_code',
            'code' => $request->string('code')->toString(),
            'redirect_uri' => $this->redirectUri(),
        ]);

        if (! $response->successful()) {
            $detail = $response->json('error_description') ?? $response->json('error');

            return $this->back($returnTo, __('Could not complete Discord sign-in: :detail', [
                'detail' => is_string($detail) && $detail !== '' ? $detail : 'HTTP '.$response->status(),
            ]));
        }

        // The guild object only comes back for a `bot` scope exchange. Its absence
        // means the consent screen was completed without actually picking a server.
        $guildId = (string) ($response->json('guild.id') ?? '');
        if ($guildId === '') {
            return $this->back($returnTo, __('Discord did not return a server. Make sure you pick a server on the authorization screen, then try again.'));
        }

        $guildName = (string) ($response->json('guild.name') ?? __('Discord server'));

        $installation = DiscordInstallation::query()->updateOrCreate(
            [
                'owner_type' => $owner::class,
                'owner_id' => (string) $owner->getKey(),
                'guild_id' => $guildId,
            ],
            [
                'guild_name' => $guildName,
                'permissions' => (string) ($response->json('guild.permissions') ?? DiscordPermissions::REQUIRED),
                'installed_by_user_id' => $user->id,
            ],
        );

        DiscordGuildClient::forgetChannelCache($installation);

        $org = match (true) {
            $owner instanceof Organization => $owner,
            $owner instanceof Team => $owner->organization,
            default => $user->currentOrganization(),
        };
        if ($org instanceof Organization) {
            audit_log($org, $user, 'notification_channel.discord_connected', $installation, null, [
                'installation_id' => (string) $installation->id,
                'guild_id' => $guildId,
                'guild_name' => $guildName,
                'owner_type' => $owner::class,
                'auth' => 'oauth',
            ]);
        }

        $destination = $this->withConnectedMarker($returnTo ?? $this->defaultReturnPath($owner), (string) $installation->id);

        return redirect()->to($destination)->with('success', __('Discord server ":guild" connected — pick a channel to finish setting up the alert.', [
            'guild' => $guildName,
        ]));
    }

    private function resolveOwner(Request $request, User $user): ?Model
    {
        $type = $request->string('owner')->toString();
        $id = $request->string('owner_id')->toString();

        if ($type === 'organization' && $id !== '') {
            $org = Organization::query()->find($id);

            return $org instanceof Organization && $org->hasMember($user) ? $org : null;
        }

        if ($type === 'team' && $id !== '') {
            $team = Team::query()->find($id);

            return $team instanceof Team ? $team : null;
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ownerFromPayload(array $payload): ?Model
    {
        $type = is_string($payload['owner_type'] ?? null) ? $payload['owner_type'] : '';
        $id = (string) ($payload['owner_id'] ?? '');

        // Only ever the three notification-channel owners — resolving an
        // arbitrary class name out of session data would be a gadget.
        $model = match ($type) {
            User::class => User::query()->find($id),
            Organization::class => Organization::query()->find($id),
            Team::class => Team::query()->find($id),
            default => null,
        };

        return $model instanceof Model ? $model : null;
    }

    private function redirectUri(): string
    {
        $configured = config('services.discord.redirect');

        return is_string($configured) && $configured !== ''
            ? $configured
            : route('notifications.oauth.discord.callback', [], true);
    }

    private function stateKey(string $nonce): string
    {
        return 'discord_oauth_'.$nonce;
    }

    /** Append `discord_connected=<id>` without clobbering a tab or filter already in the path. */
    private function withConnectedMarker(string $path, string $installationId): string
    {
        [$base, $query] = array_pad(explode('?', $path, 2), 2, '');

        parse_str($query, $params);
        $params['discord_connected'] = $installationId;

        return $base.'?'.http_build_query($params);
    }

    private function defaultReturnPath(Model $owner): string
    {
        if ($owner instanceof Organization) {
            return route('organizations.notification-channels', $owner, absolute: false);
        }

        if ($owner instanceof Team && $owner->organization !== null) {
            return route('teams.notification-channels', [$owner->organization, $owner], absolute: false);
        }

        return route('profile.notification-channels', absolute: false);
    }

    /** Same-app paths only — never a scheme/host an attacker supplied. */
    private function sanitizeReturnTo(mixed $raw): ?string
    {
        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw === '' || ! str_starts_with($raw, '/') || str_starts_with($raw, '//') || str_contains($raw, '\\')) {
            return null;
        }

        $parts = parse_url($raw);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return null;
        }

        $path = $parts['path'] ?? '/';

        // Livewire's update endpoint is POST-only, so returning a browser to it
        // is a guaranteed 405.
        if (str_contains($path, '/livewire/') || str_ends_with($path, '/update') || str_starts_with($path, '/livewire')) {
            return null;
        }

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /**
     * Return by PATH, not an absolute route() URL — providers reject `.test`
     * redirect URIs, so local setups register a tunnel host, and an absolute
     * APP_URL redirect would bounce the operator somewhere their session isn't.
     */
    private function back(?string $returnTo, ?string $error = null): RedirectResponse
    {
        $redirect = redirect()->to($returnTo ?? route('profile.notification-channels', absolute: false));

        return $error === null ? $redirect : $redirect->with('error', $error);
    }
}

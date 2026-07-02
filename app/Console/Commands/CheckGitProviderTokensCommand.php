<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\GitProviderToken;
use App\Models\NotificationInboxItem;
use App\Models\SocialAccount;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Modules\SourceControl\Services\GitProviderTokenHealth;
use Illuminate\Console\Command;

/**
 * Daily health check for stored Git credentials — both PATs
 * ({@see GitProviderToken}) and OAuth-connected accounts
 * ({@see SocialAccount}); sites deploy through either. Validates each against
 * its provider, records the REAL expiry where the provider reports one
 * (GitHub: response header; GitLab: token API), and drops an inbox + email
 * notification when a credential is rejected or expires within the warning
 * window — BEFORE deploys start failing with "Invalid username or token".
 */
class CheckGitProviderTokensCommand extends Command
{
    /** Warn when a credential expires within this many days. */
    private const EXPIRY_WARNING_DAYS = 7;

    /** OAuth providers that are Git hosts (social_accounts may hold others). */
    private const GIT_PROVIDERS = ['github', 'gitlab', 'bitbucket'];

    protected $signature = 'dply:git-tokens:check';

    protected $description = 'Validate stored Git credentials (PATs + OAuth accounts), capture real expiry, and notify owners of dead or expiring ones';

    public function handle(GitProviderTokenHealth $health): int
    {
        $checked = 0;
        $unhealthy = 0;

        GitProviderToken::query()->whereNotNull('user_id')->chunkById(50, function ($tokens) use ($health, &$checked, &$unhealthy): void {
            foreach ($tokens as $token) {
                $this->checkOne($health, $token, $checked, $unhealthy);
            }
        });

        SocialAccount::query()
            ->whereNotNull('user_id')
            ->whereIn('provider', self::GIT_PROVIDERS)
            ->chunkById(50, function ($accounts) use ($health, &$checked, &$unhealthy): void {
                foreach ($accounts as $account) {
                    $this->checkOne($health, $account, $checked, $unhealthy);
                }
            });

        $this->info(sprintf('Checked %d credential(s); %d need attention.', $checked, $unhealthy));

        return self::SUCCESS;
    }

    private function checkOne(GitProviderTokenHealth $health, GitProviderToken|SocialAccount $identity, int &$checked, int &$unhealthy): void
    {
        $checked++;
        $ok = $health->refresh($identity);
        $identity->refresh();

        if ($ok === null) {
            $this->line(sprintf('%s: skipped (provider unreachable)', $identity->displayLabel()));

            return;
        }

        $expiringSoon = $ok
            && $identity->expires_at !== null
            && $identity->expires_at->lte(now()->addDays(self::EXPIRY_WARNING_DAYS));

        if (! $ok || $expiringSoon) {
            $unhealthy++;
            $this->warn(sprintf(
                '%s: %s',
                $identity->displayLabel(),
                $ok ? 'expires '.$identity->expires_at->diffForHumans() : ($identity->validation_error ?? 'rejected'),
            ));
            $this->notifyOwner($identity, ! $ok);
        } else {
            $this->line(sprintf(
                '%s: OK%s',
                $identity->displayLabel(),
                $identity->expires_at ? ' (expires '.$identity->expires_at->toDateString().')' : '',
            ));
        }
    }

    private function notifyOwner(GitProviderToken|SocialAccount $identity, bool $rejected): void
    {
        // One open notification per credential — a daily re-run must not stack
        // a new inbox item on yesterday's unread one.
        $exists = NotificationInboxItem::query()
            ->where('user_id', $identity->user_id)
            ->whereNull('read_at')
            ->where('metadata->kind', 'git_token_health')
            ->where('metadata->token_id', $identity->id)
            ->exists();
        if ($exists) {
            return;
        }

        $label = $identity->displayLabel();
        $isOauth = $identity instanceof SocialAccount;

        // The fix differs by credential kind: a PAT is replaced in place on
        // the existing entry; an OAuth account is re-linked via the provider's
        // sign-in flow. Both live on the same settings page.
        $fixInstruction = $isOauth
            ? __('reconnect the account from Source control settings (the provider sign-in flow refreshes it without touching linked sites)')
            : __('open Source control settings and paste a new token on the existing entry so linked sites keep working');

        app(NotificationPublisher::class)->publish(
            eventKey: 'account.git_token.unhealthy',
            subject: null,
            title: $rejected
                ? __(':label was rejected by the provider', ['label' => $label])
                : __(':label expires :when', ['label' => $label, 'when' => $identity->expires_at?->diffForHumans() ?? __('soon')]),
            body: $rejected
                ? __('The provider no longer accepts this credential (:error). Deploys and repository access using it will fail until you fix it — :fix.', [
                    'error' => (string) ($identity->validation_error ?: 'rejected'),
                    'fix' => $fixInstruction,
                ])
                : __('This credential expires :when (:date). Fix it now — :fix — before deploys start failing with authentication errors.', [
                    'when' => $identity->expires_at?->diffForHumans() ?? __('soon'),
                    'date' => $identity->expires_at?->toDayDateTimeString() ?? '',
                    'fix' => $fixInstruction,
                ]),
            url: route('profile.source-control', absolute: true),
            metadata: [
                'kind' => 'git_token_health',
                'token_id' => $identity->id,
                'identity_type' => $isOauth ? 'oauth' : 'pat',
                'cta_label' => $isOauth ? __('Reconnect account') : __('Replace token'),
            ],
            recipientUsers: [(string) $identity->user_id],
        );
    }
}

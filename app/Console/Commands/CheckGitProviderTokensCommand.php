<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\GitProviderToken;
use App\Models\NotificationInboxItem;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Modules\SourceControl\Services\GitProviderTokenHealth;
use Illuminate\Console\Command;

/**
 * Daily health check for stored Git provider tokens (PATs). Validates each
 * against its provider, records the REAL expiry where the provider reports
 * one (GitHub: response header; GitLab: token API), and drops an inbox
 * notification when a token is rejected or expires within the warning window —
 * BEFORE deploys start failing with "Invalid username or token".
 */
class CheckGitProviderTokensCommand extends Command
{
    /** Warn when a token expires within this many days. */
    private const EXPIRY_WARNING_DAYS = 7;

    protected $signature = 'dply:git-tokens:check';

    protected $description = 'Validate stored Git provider tokens, capture real expiry, and notify owners of dead or expiring tokens';

    public function handle(GitProviderTokenHealth $health): int
    {
        $checked = 0;
        $unhealthy = 0;

        GitProviderToken::query()->whereNotNull('user_id')->chunkById(50, function ($tokens) use ($health, &$checked, &$unhealthy): void {
            foreach ($tokens as $token) {
                $checked++;
                $ok = $health->refresh($token);
                $token->refresh();

                if ($ok === null) {
                    $this->line(sprintf('%s: skipped (provider unreachable)', $token->displayLabel()));

                    continue;
                }

                $expiringSoon = $ok
                    && $token->expires_at !== null
                    && $token->expires_at->lte(now()->addDays(self::EXPIRY_WARNING_DAYS));

                if (! $ok || $expiringSoon) {
                    $unhealthy++;
                    $this->warn(sprintf(
                        '%s: %s',
                        $token->displayLabel(),
                        $ok ? 'expires '.$token->expires_at->diffForHumans() : ($token->validation_error ?? 'rejected'),
                    ));
                    $this->notifyOwner($token, ! $ok);
                } else {
                    $this->line(sprintf(
                        '%s: OK%s',
                        $token->displayLabel(),
                        $token->expires_at ? ' (expires '.$token->expires_at->toDateString().')' : '',
                    ));
                }
            }
        });

        $this->info(sprintf('Checked %d token(s); %d need attention.', $checked, $unhealthy));

        return self::SUCCESS;
    }

    private function notifyOwner(GitProviderToken $token, bool $rejected): void
    {
        // One open notification per token — a daily re-run must not stack a
        // new inbox item on yesterday's unread one.
        $exists = NotificationInboxItem::query()
            ->where('user_id', $token->user_id)
            ->whereNull('read_at')
            ->where('metadata->kind', 'git_token_health')
            ->where('metadata->token_id', $token->id)
            ->exists();
        if ($exists) {
            return;
        }

        $label = $token->displayLabel();

        app(NotificationPublisher::class)->publish(
            eventKey: 'account.git_token.unhealthy',
            subject: null,
            title: $rejected
                ? __(':label was rejected by the provider', ['label' => $label])
                : __(':label expires :when', ['label' => $label, 'when' => $token->expires_at?->diffForHumans() ?? __('soon')]),
            body: $rejected
                ? __('The provider no longer accepts this token (:error). Deploys and repository access using it will fail until you replace it — open Source control settings and paste a new token on the existing entry so linked sites keep working.', ['error' => (string) ($token->validation_error ?: 'rejected')])
                : __('This token expires :when (:date). Replace it now under Source control settings — paste the new token on the existing entry so every site using it keeps working — before deploys start failing with authentication errors.', [
                    'when' => $token->expires_at?->diffForHumans() ?? __('soon'),
                    'date' => $token->expires_at?->toDayDateTimeString() ?? '',
                ]),
            url: route('profile.source-control', absolute: true),
            metadata: [
                'kind' => 'git_token_health',
                'token_id' => $token->id,
                'cta_label' => __('Replace token'),
            ],
            recipientUsers: [(string) $token->user_id],
        );
    }
}

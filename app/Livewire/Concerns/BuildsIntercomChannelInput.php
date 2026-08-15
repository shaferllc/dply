<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Livewire\Settings\BulkNotificationAssignments;
use App\Models\NotificationChannel;
use App\Modules\Notifications\Channels\Intercom\IntercomMessage;
use App\Modules\Notifications\Services\IntercomClient;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Shared rules / attributes / config-blob assembly for the Intercom channel
 * type, used by all three surfaces that can create one: the settings CRUD trait
 * ({@see ManagesNotificationChannels}), the inline quick-add
 * ({@see CreatesNotificationChannelInline}), and the bulk-assign quick-add
 * ({@see BulkNotificationAssignments}).
 *
 * Intercom has seven fields where the other providers have one or two, and
 * those three surfaces each keep their own copy of the per-type match arms —
 * which is exactly how a type ends up half-working on one of them. Defining the
 * rules once here means adding Intercom to a fourth surface is a one-line call.
 *
 * @phpstan-require-extends Component
 */
trait BuildsIntercomChannelInput
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function intercomValidationRules(string $prefix): array
    {
        return [
            $prefix.'intercom_access_token' => ['required', 'string', 'max:512'],
            $prefix.'intercom_region' => ['required', 'string', Rule::in(IntercomClient::regions())],
            $prefix.'intercom_admin_id' => ['required', 'string', 'max:64'],
            $prefix.'intercom_recipient_type' => ['required', 'string', Rule::in(NotificationChannel::intercomRecipientTypes())],
            // A user/contact ID and an e-mail address are both "the recipient",
            // so the email rule can only be applied once the type is known.
            $prefix.'intercom_recipient' => array_merge(
                ['required', 'string', 'max:254'],
                in_array(
                    $this->{$prefix.'intercom_recipient_type'},
                    [NotificationChannel::INTERCOM_TO_USER_EMAIL, NotificationChannel::INTERCOM_TO_EMAIL],
                    true
                ) ? ['email'] : []
            ),
            $prefix.'intercom_message_type' => ['required', 'string', Rule::in([IntercomMessage::TYPE_INAPP, IntercomMessage::TYPE_EMAIL])],
            $prefix.'intercom_template' => ['required', 'string', Rule::in([IntercomMessage::TEMPLATE_PLAIN, IntercomMessage::TEMPLATE_PERSONAL])],
            // Intercom rejects an email message with no subject outright.
            $prefix.'intercom_subject' => [
                Rule::requiredIf(fn (): bool => $this->{$prefix.'intercom_message_type'} === IntercomMessage::TYPE_EMAIL),
                'nullable',
                'string',
                'max:160',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function intercomValidationAttributes(string $prefix): array
    {
        return [
            $prefix.'intercom_access_token' => __('access token'),
            $prefix.'intercom_region' => __('region'),
            $prefix.'intercom_admin_id' => __('admin ID'),
            $prefix.'intercom_recipient' => __('recipient'),
            $prefix.'intercom_recipient_type' => __('recipient type'),
            $prefix.'intercom_message_type' => __('message type'),
            $prefix.'intercom_template' => __('template'),
            $prefix.'intercom_subject' => __('subject'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function intercomConfigFromInput(string $prefix): array
    {
        $messageType = (string) $this->{$prefix.'intercom_message_type'};

        return [
            'access_token' => (string) $this->{$prefix.'intercom_access_token'},
            'region' => IntercomClient::normalizeRegion((string) $this->{$prefix.'intercom_region'}),
            'admin_id' => (string) $this->{$prefix.'intercom_admin_id'},
            'recipient' => (string) $this->{$prefix.'intercom_recipient'},
            'recipient_type' => (string) $this->{$prefix.'intercom_recipient_type'},
            'message_type' => $messageType,
            'template' => (string) $this->{$prefix.'intercom_template'},
            // Only meaningful on email messages; dropped otherwise so an edit
            // that flips inapp→email→inapp doesn't leave a stale subject behind.
            'subject' => $messageType === IntercomMessage::TYPE_EMAIL
                ? ((string) $this->{$prefix.'intercom_subject'} ?: null)
                : null,
        ];
    }
}

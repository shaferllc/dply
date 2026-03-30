<?php

namespace App\Actions\Concerns;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;

/**
 * Allows actions to be used as Laravel notifications.
 *
 * This trait enables actions to implement Laravel's Notification interface,
 * allowing them to be sent via Laravel's notification system. Actions using
 * this trait can be used directly with `$notifiable->notify()`.
 *
 * Note: This is NOT a decorator pattern. This is a mixin that adds notification
 * capabilities to actions, allowing them to BE notifications rather than
 * adding notification-sending behavior. For sending notifications FROM actions,
 * consider using Laravel's notification system directly in your action's handle() method.
 *
 * How it works:
 * - Actions using AsNotification implement Laravel's Notification interface methods
 * - The trait provides default implementations for via(), toMail(), and toArray()
 * - Override getNotificationChannels(), buildMailMessage(), and toNotificationArray()
 *   to customize notification behavior
 * - Actions can then be used with $notifiable->notify(new YourAction())
 *
 * Supported Channels:
 * - Mail (via toMail())
 * - Database (via toArray())
 * - Custom channels (implement to{Channel}() methods)
 *
 * @example
 * // ============================================
 * // Example 1: Basic Mail Notification
 * // ============================================
 * use Illuminate\Notifications\Messages\MailMessage;
 *
 * class InvoicePaid extends Actions
 * {
 *     use AsNotification;
 *
 *     public function __construct(
 *         public Invoice $invoice
 *     ) {}
 *
 *     public function getNotificationChannels($notifiable): array
 *     {
 *         return ['mail'];
 *     }
 *
 *     public function buildMailMessage($notifiable): MailMessage
 *     {
 *         return (new MailMessage)
 *             ->subject('Invoice Paid')
 *             ->line("Invoice #{$this->invoice->number} has been paid.")
 *             ->action('View Invoice', route('invoices.show', $this->invoice))
 *             ->line('Thank you for your business!');
 *     }
 * }
 *
 * // Usage:
 * $user->notify(new InvoicePaid($invoice));
 * @example
 * // ============================================
 * // Example 2: Database Notification
 * // ============================================
 * class ProjectAssigned extends Actions
 * {
 *     use AsNotification;
 *
 *     public function __construct(
 *         public Project $project,
 *         public User $assignedBy
 *     ) {}
 *
 *     public function getNotificationChannels($notifiable): array
 *     {
 *         return ['database'];
 *     }
 *
 *     public function toNotificationArray($notifiable): array
 *     {
 *         return [
 *             'project_id' => $this->project->id,
 *             'project_name' => $this->project->name,
 *             'assigned_by_id' => $this->assignedBy->id,
 *             'assigned_by_name' => $this->assignedBy->name,
 *             'message' => "You have been assigned to project: {$this->project->name}",
 *         ];
 *     }
 * }
 *
 * // Usage:
 * $user->notify(new ProjectAssigned($project, $currentUser));
 *
 * // Retrieve notifications:
 * $notifications = $user->notifications;
 * @example
 * // ============================================
 * // Example 3: Multiple Channels
 * // ============================================
 * use Illuminate\Notifications\Messages\MailMessage;
 *
 * class TeamInvitation extends Actions
 * {
 *     use AsNotification;
 *
 *     public function __construct(
 *         public Team $team,
 *         public User $invitedBy
 *     ) {}
 *
 *     public function getNotificationChannels($notifiable): array
 *     {
 *         return ['mail', 'database'];
 *     }
 *
 *     public function buildMailMessage($notifiable): MailMessage
 *     {
 *         return (new MailMessage)
 *             ->subject("You've been invited to join {$this->team->name}")
 *             ->greeting("Hello {$notifiable->name}!")
 *             ->line("{$this->invitedBy->name} has invited you to join {$this->team->name}.")
 *             ->action('Accept Invitation', route('teams.invitations.accept', $this->team))
 *             ->line('If you did not expect this invitation, you can ignore this email.');
 *     }
 *
 *     public function toNotificationArray($notifiable): array
 *     {
 *         return [
 *             'team_id' => $this->team->id,
 *             'team_name' => $this->team->name,
 *             'invited_by_id' => $this->invitedBy->id,
 *             'invited_by_name' => $this->invitedBy->name,
 *             'message' => "You have been invited to join {$this->team->name}",
 *         ];
 *     }
 * }
 *
 * // Usage:
 * $user->notify(new TeamInvitation($team, $currentUser));
 * @example
 * // ============================================
 * // Example 4: Conditional Channels
 * // ============================================
 * class OrderShipped extends Actions
 * {
 *     use AsNotification;
 *
 *     public function __construct(
 *         public Order $order
 *     ) {}
 *
 *     public function getNotificationChannels($notifiable): array
 *     {
 *         $channels = ['database'];
 *
 *         // Only send email if user has email notifications enabled
 *         if ($notifiable->email_notifications_enabled) {
 *             $channels[] = 'mail';
 *         }
 *
 *         // Send SMS for high-value orders
 *         if ($this->order->total > 1000) {
 *             $channels[] = 'nexmo'; // Requires toNexmo() method
 *         }
 *
 *         return $channels;
 *     }
 *
 *     public function buildMailMessage($notifiable): MailMessage
 *     {
 *         return (new MailMessage)
 *             ->subject("Order #{$this->order->number} has been shipped")
 *             ->line("Your order has been shipped and is on its way!")
 *             ->line("Tracking number: {$this->order->tracking_number}")
 *             ->action('Track Order', route('orders.track', $this->order));
 *     }
 *
 *     public function toNotificationArray($notifiable): array
 *     {
 *         return [
 *             'order_id' => $this->order->id,
 *             'order_number' => $this->order->number,
 *             'tracking_number' => $this->order->tracking_number,
 *             'message' => "Order #{$this->order->number} has been shipped",
 *         ];
 *     }
 * }
 * @example
 * // ============================================
 * // Example 5: Rich Mail Notifications
 * // ============================================
 * use Illuminate\Notifications\Messages\MailMessage;
 *
 * class PaymentReceived extends Actions
 * {
 *     use AsNotification;
 *
 *     public function __construct(
 *         public Payment $payment
 *     ) {}
 *
 *     public function getNotificationChannels($notifiable): array
 *     {
 *         return ['mail'];
 *     }
 *
 *     public function buildMailMessage($notifiable): MailMessage
 *     {
 *         return (new MailMessage)
 *             ->subject('Payment Received')
 *             ->greeting("Hello {$notifiable->name}!")
 *             ->line("We've received your payment of {$this->formatAmount($this->payment->amount)}.")
 *             ->line("Payment Details:")
 *             ->line("- Payment ID: {$this->payment->id}")
 *             ->line("- Date: {$this->payment->created_at->format('M d, Y')}")
 *             ->line("- Method: {$this->payment->method}")
 *             ->action('View Payment', route('payments.show', $this->payment))
 *             ->line('Thank you for your payment!')
 *             ->salutation('Best regards, The Team');
 *     }
 *
 *     protected function formatAmount(float $amount): string
 *     {
 *         return '$'.number_format($amount, 2);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 6: Using in Action's handle() Method
 * // ============================================
 * class ProcessOrder extends Actions
 * {
 *     use AsValidated;
 *
 *     public function handle(Order $order): Order
 *     {
 *         $order->status = 'processed';
 *         $order->save();
 *
 *         // Send notification using an action as notification
 *         $order->user->notify(new OrderProcessed($order));
 *
 *         return $order;
 *     }
 * }
 *
 * // Separate action that IS a notification
 * class OrderProcessed extends Actions
 * {
 *     use AsNotification;
 *
 *     public function __construct(
 *         public Order $order
 *     ) {}
 *
 *     public function getNotificationChannels($notifiable): array
 *     {
 *         return ['mail', 'database'];
 *     }
 *
 *     public function buildMailMessage($notifiable): MailMessage
 *     {
 *         return (new MailMessage)
 *             ->subject("Order #{$this->order->number} is being processed")
 *             ->line("Your order is now being processed and will ship soon.");
 *     }
 *
 *     public function toNotificationArray($notifiable): array
 *     {
 *         return [
 *             'order_id' => $this->order->id,
 *             'order_number' => $this->order->number,
 *             'message' => "Order #{$this->order->number} is being processed",
 *         ];
 *     }
 * }
 * @example
 * // ============================================
 * // Example 7: Notification with Attachments
 * // ============================================
 * use Illuminate\Notifications\Messages\MailMessage;
 *
 * class InvoiceGenerated extends Actions
 * {
 *     use AsNotification;
 *
 *     public function __construct(
 *         public Invoice $invoice
 *     ) {}
 *
 *     public function getNotificationChannels($notifiable): array
 *     {
 *         return ['mail'];
 *     }
 *
 *     public function buildMailMessage($notifiable): MailMessage
 *     {
 *         $message = (new MailMessage)
 *             ->subject("Invoice #{$this->invoice->number}")
 *             ->line("Please find your invoice attached.")
 *             ->attach(storage_path("invoices/{$this->invoice->pdf_path}"), [
 *                 'as' => "invoice-{$this->invoice->number}.pdf",
 *                 'mime' => 'application/pdf',
 *             ]);
 *
 *         return $message;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 8: Custom Notification Data
 * // ============================================
 * class CommentPosted extends Actions
 * {
 *     use AsNotification;
 *
 *     public function __construct(
 *         public Comment $comment,
 *         public Post $post
 *     ) {}
 *
 *     public function getNotificationChannels($notifiable): array
 *     {
 *         // Only notify if user wants comment notifications
 *         if (! $notifiable->wants_comment_notifications) {
 *             return [];
 *         }
 *
 *         return ['database'];
 *     }
 *
 *     public function toNotificationArray($notifiable): array
 *     {
 *         return [
 *             'comment_id' => $this->comment->id,
 *             'post_id' => $this->post->id,
 *             'post_title' => $this->post->title,
 *             'commenter_id' => $this->comment->user_id,
 *             'commenter_name' => $this->comment->user->name,
 *             'comment_preview' => Str::limit($this->comment->body, 100),
 *             'message' => "{$this->comment->user->name} commented on your post: {$this->post->title}",
 *             'url' => route('posts.show', $this->post).'#comment-'.$this->comment->id,
 *         ];
 *     }
 * }
 * @example
 * // ============================================
 * // Example 9: Notification with Markdown
 * // ============================================
 * use Illuminate\Notifications\Messages\MailMessage;
 *
 * class WelcomeUser extends Actions
 * {
 *     use AsNotification;
 *
 *     public function __construct(
 *         public User $user
 *     ) {}
 *
 *     public function getNotificationChannels($notifiable): array
 *     {
 *         return ['mail'];
 *     }
 *
 *     public function buildMailMessage($notifiable): MailMessage
 *     {
 *         return (new MailMessage)
 *             ->subject('Welcome to Our Platform!')
 *             ->markdown('emails.welcome', [
 *                 'user' => $this->user,
 *                 'verificationUrl' => route('verification.verify', $this->user),
 *             ]);
 *     }
 * }
 *
 * // resources/views/emails/welcome.blade.php:
 * // @component('mail::message')
 * // # Welcome {{ $user->name }}!
 * // ...
 * // @endcomponent
 * @example
 * // ============================================
 * // Example 10: Notification Queuing
 * // ============================================
 * use Illuminate\Bus\Queueable;
 * use Illuminate\Contracts\Queue\ShouldQueue;
 * use Illuminate\Notifications\Messages\MailMessage;
 *
 * class BulkEmailNotification extends Actions implements ShouldQueue
 * {
 *     use AsNotification, Queueable;
 *
 *     public function __construct(
 *         public string $subject,
 *         public string $message
 *     ) {}
 *
 *     public function getNotificationChannels($notifiable): array
 *     {
 *         return ['mail'];
 *     }
 *
 *     public function buildMailMessage($notifiable): MailMessage
 *     {
 *         return (new MailMessage)
 *             ->subject($this->subject)
 *             ->line($this->message);
 *     }
 * }
 *
 * // Usage - notification will be queued:
 * $user->notify(new BulkEmailNotification('Newsletter', 'Check out our latest updates!'));
 * @example
 * // ============================================
 * // Example 11: Notification with Custom Channel
 * // ============================================
 * class SlackNotification extends Actions
 * {
 *     use AsNotification;
 *
 *     public function __construct(
 *         public string $message
 *     ) {}
 *
 *     public function getNotificationChannels($notifiable): array
 *     {
 *         return ['slack'];
 *     }
 *
 *     // Laravel will automatically call toSlack() if channel is 'slack'
 *     public function toSlack($notifiable)
 *     {
 *         return (new \Illuminate\Notifications\Messages\SlackMessage)
 *             ->content($this->message)
 *             ->from('Bot')
 *             ->to('#general');
 *     }
 * }
 * @example
 * // ============================================
 * // Example 12: Notification with Localization
 * // ============================================
 * use Illuminate\Notifications\Messages\MailMessage;
 *
 * class LocalizedNotification extends Actions
 * {
 *     use AsNotification;
 *
 *     public function __construct(
 *         public User $user
 *     ) {}
 *
 *     public function getNotificationChannels($notifiable): array
 *     {
 *         return ['mail'];
 *     }
 *
 *     public function buildMailMessage($notifiable): MailMessage
 *     {
 *         $locale = $notifiable->locale ?? 'en';
 *
 *         return (new MailMessage)
 *             ->subject(__('notifications.welcome.subject', [], $locale))
 *             ->line(__('notifications.welcome.line1', ['name' => $notifiable->name], $locale));
 *     }
 * }
 * @example
 * // ============================================
 * // Example 13: Notification with Rate Limiting
 * // ============================================
 * class RateLimitedNotification extends Actions
 * {
 *     use AsNotification;
 *
 *     public function __construct(
 *         public string $message
 *     ) {}
 *
 *     public function getNotificationChannels($notifiable): array
 *     {
 *         return ['mail'];
 *     }
 *
 *     public function buildMailMessage($notifiable): MailMessage
 *     {
 *         return (new MailMessage)
 *             ->subject('Important Update')
 *             ->line($this->message);
 *     }
 *
 *     // Override to add rate limiting
 *     public function via($notifiable): array
 *     {
 *         // Only send if not rate limited
 *         $key = 'notifications:rate_limit:'.$notifiable->id;
 *
 *         if (cache()->has($key)) {
 *             return []; // Don't send
 *         }
 *
 *         cache()->put($key, true, now()->addHours(1));
 *
 *         return parent::via($notifiable);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 14: Notification with User Preferences
 * // ============================================
 * class UserPreferenceNotification extends Actions
 * {
 *     use AsNotification;
 *
 *     public function __construct(
 *         public string $type,
 *         public array $data
 *     ) {}
 *
 *     public function getNotificationChannels($notifiable): array
 *     {
 *         $channels = [];
 *
 *         // Check user preferences
 *         $preferences = $notifiable->notification_preferences ?? [];
 *
 *         if ($preferences[$this->type]['email'] ?? true) {
 *             $channels[] = 'mail';
 *         }
 *
 *         if ($preferences[$this->type]['database'] ?? true) {
 *             $channels[] = 'database';
 *         }
 *
 *         return $channels;
 *     }
 *
 *     public function buildMailMessage($notifiable): MailMessage
 *     {
 *         return (new MailMessage)
 *             ->subject($this->data['subject'] ?? 'Notification')
 *             ->line($this->data['message'] ?? '');
 *     }
 *
 *     public function toNotificationArray($notifiable): array
 *     {
 *         return $this->data;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 15: Real-World Usage - Task Assignment
 * // ============================================
 * use Illuminate\Notifications\Messages\MailMessage;
 *
 * class TaskAssigned extends Actions
 * {
 *     use AsNotification;
 *
 *     public function __construct(
 *         public Task $task,
 *         public User $assignedBy
 *     ) {}
 *
 *     public function getNotificationChannels($notifiable): array
 *     {
 *         return ['mail', 'database'];
 *     }
 *
 *     public function buildMailMessage($notifiable): MailMessage
 *     {
 *         return (new MailMessage)
 *             ->subject("New Task: {$this->task->title}")
 *             ->greeting("Hello {$notifiable->name}!")
 *             ->line("{$this->assignedBy->name} has assigned you a new task.")
 *             ->line("Task: {$this->task->title}")
 *             ->line("Due Date: {$this->task->due_date->format('M d, Y')}")
 *             ->action('View Task', route('tasks.show', $this->task))
 *             ->line('Thank you for your hard work!');
 *     }
 *
 *     public function toNotificationArray($notifiable): array
 *     {
 *         return [
 *             'task_id' => $this->task->id,
 *             'task_title' => $this->task->title,
 *             'assigned_by_id' => $this->assignedBy->id,
 *             'assigned_by_name' => $this->assignedBy->name,
 *             'due_date' => $this->task->due_date->toIso8601String(),
 *             'message' => "You have been assigned: {$this->task->title}",
 *         ];
 *     }
 * }
 *
 * // Usage in an action:
 * class AssignTask extends Actions
 * {
 *     public function handle(Task $task, User $assignee, User $assignedBy): Task
 *     {
 *         $task->assignee_id = $assignee->id;
 *         $task->save();
 *
 *         // Send notification
 *         $assignee->notify(new TaskAssigned($task, $assignedBy));
 *
 *         return $task;
 *     }
 * }
 *
 * @see Notification
 * @see Notifiable
 */
trait AsNotification
{
    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     */
    public function via($notifiable): array
    {
        return $this->hasMethod('getNotificationChannels')
            ? $this->callMethod('getNotificationChannels', [$notifiable])
            : ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return MailMessage|null
     */
    public function toMail($notifiable)
    {
        if ($this->hasMethod('buildMailMessage')) {
            return $this->callMethod('buildMailMessage', [$notifiable]);
        }

        return null;
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     */
    public function toArray($notifiable): array
    {
        return $this->hasMethod('toNotificationArray')
            ? $this->callMethod('toNotificationArray', [$notifiable])
            : [];
    }
}

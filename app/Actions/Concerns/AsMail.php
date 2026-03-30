<?php

namespace App\Actions\Concerns;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Mail;

/**
 * Allows actions to be used as Laravel mailables.
 *
 * This trait enables actions to implement Laravel's Mailable interface,
 * allowing them to be sent via Laravel's mail system. Actions using this
 * trait can be used directly with `Mail::to()->send()`.
 *
 * How it works:
 * - Actions using AsMail implement Laravel's Mailable interface methods
 * - The trait provides default implementations for `build()` and `envelope()`
 * - Override `buildMail()`, `view()`, `getMailData()`, or `envelope()` to customize
 * - Actions can then be used with `Mail::to($user)->send(new YourAction())`
 *
 * Benefits:
 * - Use actions as mailables
 * - Clean separation of concerns
 * - Reusable email logic
 * - Easy to test
 * - Works with Laravel's mail system
 * - Supports all Mailable features (attachments, queuing, etc.)
 *
 * Note: This is NOT a decorator pattern. This is a mixin that adds mailable
 * capabilities to actions, allowing them to BE mailables rather than
 * adding mailing behavior. For sending emails FROM actions, consider using
 * Laravel's Mail facade directly in your action's handle() method.
 *
 * Supported Methods:
 * - `buildMail()`: Primary method for building the mailable (returns $this)
 * - `getViewName()`: Get the view name (returns string) - Preferred method
 * - `view()`: Get the view name (returns string) - For backward compatibility only
 *   Note: This conflicts with Laravel's Mailable view() method, use getViewName() instead
 * - `getMailData()`: Get data to pass to the view (returns array)
 * - `envelope()`: Get the envelope (Laravel 9+) (returns Envelope)
 * - `subject()`: Get the subject line (returns string)
 * - `attachments()`: Get attachments (returns array)
 *
 * @example
 * // ============================================
 * // Example 1: Basic Mailable Action
 * // ============================================
 * use Illuminate\Mail\Mailables\Content;
 * use Illuminate\Mail\Mailables\Envelope;
 *
 * class WelcomeEmail extends Actions
 * {
 *     use AsMail;
 *
 *     public function __construct(
 *         public User $user
 *     ) {}
 *
 *     public function buildMail()
 *     {
 *         return $this->view('emails.welcome')
 *             ->subject('Welcome to Our Platform')
 *             ->with([
 *                 'user' => $this->user,
 *                 'loginUrl' => route('login'),
 *             ]);
 *     }
 * }
 *
 * // Send email:
 * Mail::to($user)->send(new WelcomeEmail($user));
 * @example
 * // ============================================
 * // Example 2: Using getViewName() and getMailData() Methods
 * // ============================================
 * class OrderConfirmation extends Actions
 * {
 *     use AsMail;
 *
 *     public function __construct(
 *         public Order $order
 *     ) {}
 *
 *     public function getViewName(): string
 *     {
 *         return 'emails.order-confirmation';
 *     }
 *
 *     public function getMailData(): array
 *     {
 *         return [
 *             'order' => $this->order,
 *             'items' => $this->order->items,
 *             'total' => $this->order->total,
 *             'trackingUrl' => route('orders.track', $this->order),
 *         ];
 *     }
 *
 *     public function subject(): string
 *     {
 *         return "Order #{$this->order->number} Confirmation";
 *     }
 * }
 *
 * // Send email:
 * Mail::to($order->customer)->send(new OrderConfirmation($order));
 * @example
 * // ============================================
 * // Example 3: Using Laravel 9+ Envelope Method
 * // ============================================
 * use Illuminate\Mail\Mailables\Envelope;
 *
 * class InvoiceEmail extends Actions
 * {
 *     use AsMail;
 *
 *     public function __construct(
 *         public Invoice $invoice
 *     ) {}
 *
 *     public function envelope(): Envelope
 *     {
 *         return new Envelope(
 *             subject: "Invoice #{$this->invoice->number}",
 *             from: new \Illuminate\Mail\Mailables\Address('billing@example.com', 'Billing Team'),
 *             replyTo: [
 *                 new \Illuminate\Mail\Mailables\Address('support@example.com', 'Support Team'),
 *             ],
 *         );
 *     }
 *
 *     public function getViewName(): string
 *     {
 *         return 'emails.invoice';
 *     }
 *
 *     public function getMailData(): array
 *     {
 *         return [
 *             'invoice' => $this->invoice,
 *             'paymentUrl' => route('invoices.pay', $this->invoice),
 *         ];
 *     }
 * }
 *
 * // Send email:
 * Mail::to($invoice->customer)->send(new InvoiceEmail($invoice));
 * @example
 * // ============================================
 * // Example 4: Mailable with Attachments
 * // ============================================
 * use Illuminate\Mail\Mailables\Attachment;
 *
 * class ReportEmail extends Actions
 * {
 *     use AsMail;
 *
 *     public function __construct(
 *         public User $user,
 *         public string $reportPath
 *     ) {}
 *
 *     public function buildMail()
 *     {
 *         return $this->view('emails.report')
 *             ->subject('Your Monthly Report')
 *             ->attach($this->reportPath, [
 *                 'as' => 'monthly-report.pdf',
 *                 'mime' => 'application/pdf',
 *             ])
 *             ->with([
 *                 'user' => $this->user,
 *             ]);
 *     }
 * }
 *
 * // Send email:
 * Mail::to($user)->send(new ReportEmail($user, $reportPath));
 * @example
 * // ============================================
 * // Example 5: Queued Mailable
 * // ============================================
 * use Illuminate\Bus\Queueable;
 * use Illuminate\Contracts\Queue\ShouldQueue;
 *
 * class BulkNewsletter extends Actions implements ShouldQueue
 * {
 *     use AsMail, Queueable;
 *
 *     public function __construct(
 *         public User $user,
 *         public array $newsletterData
 *     ) {}
 *
 *     public function getViewName(): string
 *     {
 *         return 'emails.newsletter';
 *     }
 *
 *     public function getMailData(): array
 *     {
 *         return [
 *             'user' => $this->user,
 *             'articles' => $this->newsletterData['articles'],
 *             'unsubscribeUrl' => route('newsletter.unsubscribe', $this->user),
 *         ];
 *     }
 *
 *     public function subject(): string
 *     {
 *         return $this->newsletterData['subject'] ?? 'Newsletter';
 *     }
 * }
 *
 * // Send queued email:
 * Mail::to($user)->send(new BulkNewsletter($user, $data));
 * // Email is queued and sent asynchronously
 * @example
 * // ============================================
 * // Example 6: Mailable with Markdown
 * // ============================================
 * class MarkdownEmail extends Actions
 * {
 *     use AsMail;
 *
 *     public function __construct(
 *         public User $user
 *     ) {}
 *
 *     public function buildMail()
 *     {
 *         return $this->markdown('emails.welcome', [
 *             'user' => $this->user,
 *             'actionUrl' => route('dashboard'),
 *         ])
 *             ->subject('Welcome!');
 *     }
 * }
 *
 * // Send email:
 * Mail::to($user)->send(new MarkdownEmail($user));
 * @example
 * // ============================================
 * // Example 7: Mailable with CC and BCC
 * // ============================================
 * class TeamInvitation extends Actions
 * {
 *     use AsMail;
 *
 *     public function __construct(
 *         public Team $team,
 *         public User $invitedUser,
 *         public User $invitedBy
 *     ) {}
 *
 *     public function buildMail()
 *     {
 *         return $this->view('emails.team-invitation')
 *             ->subject("You've been invited to join {$this->team->name}")
 *             ->cc($this->invitedBy->email)
 *             ->bcc('admin@example.com')
 *             ->with([
 *                 'team' => $this->team,
 *                 'invitedUser' => $this->invitedUser,
 *                 'invitedBy' => $this->invitedBy,
 *                 'acceptUrl' => route('teams.invitations.accept', $this->team),
 *             ]);
 *     }
 * }
 *
 * // Send email:
 * Mail::to($invitedUser)->send(new TeamInvitation($team, $invitedUser, $invitedBy));
 * @example
 * // ============================================
 * // Example 8: Mailable with Custom From Address
 * // ============================================
 * class CustomFromEmail extends Actions
 * {
 *     use AsMail;
 *
 *     public function __construct(
 *         public User $user
 *     ) {}
 *
 *     public function buildMail()
 *     {
 *         return $this->view('emails.custom')
 *             ->subject('Custom Email')
 *             ->from('noreply@example.com', 'No Reply')
 *             ->replyTo('support@example.com', 'Support Team')
 *             ->with(['user' => $this->user]);
 *     }
 * }
 *
 * // Send email:
 * Mail::to($user)->send(new CustomFromEmail($user));
 * @example
 * // ============================================
 * // Example 9: Mailable with Priority
 * // ============================================
 * class UrgentNotification extends Actions
 * {
 *     use AsMail;
 *
 *     public function __construct(
 *         public User $user,
 *         public string $message
 *     ) {}
 *
 *     public function buildMail()
 *     {
 *         return $this->view('emails.urgent')
 *             ->subject('URGENT: Action Required')
 *             ->priority('high')
 *             ->with([
 *                 'user' => $this->user,
 *                 'message' => $this->message,
 *             ]);
 *     }
 * }
 *
 * // Send email:
 * Mail::to($user)->send(new UrgentNotification($user, 'Please verify your account'));
 * @example
 * // ============================================
 * // Example 10: Mailable with Tags and Metadata
 * // ============================================
 * class TaggedEmail extends Actions
 * {
 *     use AsMail;
 *
 *     public function __construct(
 *         public User $user,
 *         public string $type
 *     ) {}
 *
 *     public function buildMail()
 *     {
 *         return $this->view('emails.tagged')
 *             ->subject('Tagged Email')
 *             ->tag($this->type)
 *             ->metadata('user_id', (string) $this->user->id)
 *             ->metadata('email_type', $this->type)
 *             ->with(['user' => $this->user]);
 *     }
 * }
 *
 * // Send email (works with services like Mailgun, Postmark):
 * Mail::to($user)->send(new TaggedEmail($user, 'newsletter'));
 * @example
 * // ============================================
 * // Example 11: Conditional Email Content
 * // ============================================
 * class ConditionalEmail extends Actions
 * {
 *     use AsMail;
 *
 *     public function __construct(
 *         public User $user,
 *         public string $locale
 *     ) {}
 *
 *     public function getViewName(): string
 *     {
 *         // Different views based on locale
 *         return match ($this->locale) {
 *             'es' => 'emails.welcome-es',
 *             'fr' => 'emails.welcome-fr',
 *             default => 'emails.welcome',
 *         };
 *     }
 *
 *     public function getMailData(): array
 *     {
 *         return [
 *             'user' => $this->user,
 *             'locale' => $this->locale,
 *         ];
 *     }
 *
 *     public function subject(): string
 *     {
 *         return match ($this->locale) {
 *             'es' => 'Bienvenido',
 *             'fr' => 'Bienvenue',
 *             default => 'Welcome',
 *         };
 *     }
 * }
 *
 * // Send email:
 * Mail::to($user)->send(new ConditionalEmail($user, 'es'));
 * @example
 * // ============================================
 * // Example 12: Mailable with Raw Content
 * // ============================================
 * class RawEmail extends Actions
 * {
 *     use AsMail;
 *
 *     public function __construct(
 *         public User $user,
 *         public string $content
 *     ) {}
 *
 *     public function buildMail()
 *     {
 *         return $this->html($this->content)
 *             ->subject('Raw Content Email')
 *             ->text('Plain text version');
 *     }
 * }
 *
 * // Send email:
 * Mail::to($user)->send(new RawEmail($user, '<h1>Hello</h1>'));
 * @example
 * // ============================================
 * // Example 13: Using in Action's handle() Method
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
 *         // Send email using an action as mailable
 *         Mail::to($order->customer)->send(new OrderProcessed($order));
 *
 *         return $order;
 *     }
 * }
 *
 * // Separate action that IS a mailable
 * class OrderProcessed extends Actions
 * {
 *     use AsMail;
 *
 *     public function __construct(
 *         public Order $order
 *     ) {}
 *
 *     public function getViewName(): string
 *     {
 *         return 'emails.order-processed';
 *     }
 *
 *     public function getMailData(): array
 *     {
 *         return [
 *             'order' => $this->order,
 *             'trackingUrl' => route('orders.track', $this->order),
 *         ];
 *     }
 *
 *     public function subject(): string
 *     {
 *         return "Order #{$this->order->number} is being processed";
 *     }
 * }
 * @example
 * // ============================================
 * // Example 14: Mailable with Multiple Recipients
 * // ============================================
 * class TeamAnnouncement extends Actions
 * {
 *     use AsMail;
 *
 *     public function __construct(
 *         public Team $team,
 *         public string $announcement
 *     ) {}
 *
 *     public function buildMail()
 *     {
 *         $recipients = $this->team->members->pluck('email')->toArray();
 *
 *         return $this->view('emails.team-announcement')
 *             ->subject("Team Announcement: {$this->team->name}")
 *             ->to($recipients)
 *             ->with([
 *                 'team' => $this->team,
 *                 'announcement' => $this->announcement,
 *             ]);
 *     }
 * }
 *
 * // Send email:
 * Mail::send(new TeamAnnouncement($team, 'New project starting next week'));
 * @example
 * // ============================================
 * // Example 15: Real-World Usage - Password Reset Email
 * // ============================================
 * class PasswordResetEmail extends Actions
 * {
 *     use AsMail;
 *
 *     public function __construct(
 *         public User $user,
 *         public string $token
 *     ) {}
 *
 *     public function envelope(): Envelope
 *     {
 *         return new Envelope(
 *             subject: 'Reset Your Password',
 *             from: new \Illuminate\Mail\Mailables\Address('noreply@example.com', 'Security Team'),
 *         );
 *     }
 *
 *     public function getViewName(): string
 *     {
 *         return 'emails.password-reset';
 *     }
 *
 *     public function getMailData(): array
 *     {
 *         return [
 *             'user' => $this->user,
 *             'resetUrl' => route('password.reset', [
 *                 'token' => $this->token,
 *                 'email' => $this->user->email,
 *             ]),
 *             'expiresIn' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60),
 *         ];
 *     }
 * }
 *
 * // Usage in PasswordReset action:
 * class SendPasswordReset extends Actions
 * {
 *     public function handle(User $user): void
 *     {
 *         $token = Password::createToken($user);
 *         Mail::to($user)->send(new PasswordResetEmail($user, $token));
 *     }
 * }
 *
 * @see Mailable
 * @see Envelope
 * @see Mail
 */
trait AsMail
{
    /**
     * Build the message.
     * This method is called by Laravel's Mailable system.
     *
     * @return $this
     */
    public function build()
    {
        // Try buildMail() method first
        if (method_exists($this, 'buildMail')) {
            return $this->buildMail();
        }

        // Try getViewName() and getMailData() methods
        if (method_exists($this, 'getViewName') && method_exists($this, 'getMailData')) {
            $viewName = $this->getViewName();
            $data = $this->getMailData();

            $mailable = $this->view($viewName)->with($data);

            // Set subject if method exists
            if (method_exists($this, 'subject')) {
                $mailable->subject($this->subject());
            }

            return $mailable;
        }

        // Fallback: try view() as a getter method (for backward compatibility)
        // Note: This conflicts with Mailable's view() method, so prefer getViewName()
        if (method_exists($this, 'view') && method_exists($this, 'getMailData')) {
            $viewName = call_user_func([$this, 'view']);
            if (is_string($viewName)) {
                $data = $this->getMailData();
                $mailable = $this->view($viewName)->with($data);

                if (method_exists($this, 'subject')) {
                    $mailable->subject($this->subject());
                }

                return $mailable;
            }
        }

        return $this;
    }

    /**
     * Get the view name for the email.
     * Override this method to specify the view.
     */
    protected function getViewName(): string
    {
        return 'emails.default';
    }

    /**
     * Get data to pass to the email view.
     * Override this method to provide view data.
     */
    protected function getMailData(): array
    {
        return [];
    }
}

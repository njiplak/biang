<?php

namespace App\Notifications\Billing;

use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Section 4: "because the trial auto-charges, we owe the customer clear warning
 * emails at 3 days and 1 day before. Skipping those turns conversions into
 * disputes, and with our payment setup a dispute is a formal chargeback."
 */
class TrialEndingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Workspace $workspace,
        private readonly int $daysLeft,
    ) {}

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $when = $this->daysLeft === 1 ? 'tomorrow' : "in {$this->daysLeft} days";

        return (new MailMessage)
            ->subject("Your {$this->workspace->name} trial ends {$when}")
            ->greeting('Your trial is nearly over')
            // Stated plainly and up front: this is the whole point of the email.
            ->line("The trial for {$this->workspace->name} ends {$when}, and the card on file will be charged automatically.")
            ->line('If you would rather not continue, cancel before then and you will not be charged. The workspace becomes read-only — nothing is deleted.')
            ->action('Review your plan', url('/billing'))
            ->line('You will keep access to everything you have created either way.');
    }
}

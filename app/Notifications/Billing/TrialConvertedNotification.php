<?php

namespace App\Notifications\Billing;

use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Section 4: the trial has auto-charged and become a normal subscription. */
class TrialConvertedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Workspace $workspace, private readonly string $plan) {}

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->workspace->name} is now on {$this->plan}")
            ->greeting('Your trial has converted')
            ->line("The trial for {$this->workspace->name} has ended and the workspace is now on {$this->plan}.")
            // Section 8: Dodo is the legal seller, so the invoice is theirs.
            ->line('Your invoice is issued by our payment provider and is available from the billing page.')
            ->action('View billing', url('/billing'));
    }
}

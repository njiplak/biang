<?php

namespace App\Notifications\Billing;

use App\Models\Workspace;
use App\Notifications\Billing\Concerns\MentionsSupport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Section 9: "Grace period ends → Workspace goes read-only. Email explains
 * exactly why."
 *
 * The tone matters commercially. Section 6: "A customer whose card expired is
 * not a customer who left" - so this says what is still possible, not just what
 * has stopped.
 */
class GracePeriodEndedNotification extends Notification implements ShouldQueue
{
    use MentionsSupport, Queueable;

    public function __construct(private readonly Workspace $workspace) {}

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withSupportLine((new MailMessage)
            ->subject("{$this->workspace->name} is now read-only")
            ->greeting('We could not take payment')
            ->line("We tried several times to charge the card for {$this->workspace->name} and it did not go through, so the workspace is now read-only.")
            ->line('Nothing has been deleted. You can still read and export everything, and updating the card restores full access straight away.')
            ->action('Update payment details', url('/billing')));
    }
}

<?php

namespace App\Notifications\Billing;

use App\Models\Workspace;
use App\Notifications\Billing\Concerns\MentionsSupport;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Section 9, first row: "Renewal fails → Email + banner in the app. Full access
 * continues."
 *
 * The banner alone is not enough. A customer whose card expired is not opening
 * the app - that is usually exactly why they have not noticed - so the email is
 * the part that actually reaches them.
 *
 * Section 6 sets the tone: "a customer whose card expired is not a customer who
 * left. Locking them out on day one of a failed payment is how you turn a card
 * problem into a cancellation." So this leads with what still works.
 */
class PaymentFailedNotification extends Notification implements ShouldQueue
{
    use MentionsSupport, Queueable;

    public function __construct(
        private readonly Workspace $workspace,
        private readonly CarbonInterface $graceEndsAt,
    ) {}

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withSupportLine((new MailMessage)
            ->subject("We could not take payment for {$this->workspace->name}")
            ->greeting('A payment did not go through')
            ->line("The last payment for {$this->workspace->name} was declined. It is usually an expired card or a bank that blocked the charge.")
            // Said before the deadline, deliberately: the reassurance is the
            // point of the email, and burying it under a date reads as a threat.
            ->line('Nothing has changed yet. Everyone on your team keeps full access while we retry.')
            ->line("If it is still unpaid on {$this->graceEndsAt->toFormattedDayDateString()}, the workspace becomes read-only — you would keep everything and could still export it, but nobody could make changes.")
            ->action('Update payment details', url('/billing'))
            ->line('Updating the card fixes this immediately, and the retry usually goes through within a day.'));
    }
}

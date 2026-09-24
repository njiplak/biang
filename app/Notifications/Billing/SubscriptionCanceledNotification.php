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
 * The written record of a cancellation made in our app. With a merchant of
 * record, "I cancelled and was still charged" is argued from evidence, and the
 * customer should hold the same evidence we do.
 *
 * $endsAt set means the cancellation is scheduled and access runs until then;
 * null means it ended immediately.
 */
class SubscriptionCanceledNotification extends Notification implements ShouldQueue
{
    use MentionsSupport, Queueable;

    public function __construct(
        private readonly Workspace $workspace,
        private readonly string $plan,
        private readonly ?CarbonInterface $endsAt,
    ) {}

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject("Your {$this->plan} subscription for {$this->workspace->name} is cancelled");

        if ($this->endsAt !== null) {
            $date = $this->endsAt->toFormattedDayDateString();

            $message
                ->greeting('Your cancellation is confirmed')
                ->line("The {$this->plan} subscription for {$this->workspace->name} will end on {$date}. You will not be charged again.")
                ->line('Until then everything keeps working as it does today. After that the workspace becomes read-only — nothing is deleted.')
                ->action('Changed your mind? Resume the subscription', url('/billing'));
        } else {
            $message
                ->greeting('Your subscription has ended')
                ->line("The {$this->plan} subscription for {$this->workspace->name} has ended and you will not be charged again.")
                ->line('The workspace is now read-only. Everything in it is kept, and choosing a plan turns writing back on.')
                ->action('View billing', url('/billing'));
        }

        return $this->withSupportLine($message);
    }
}

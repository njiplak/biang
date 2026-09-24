<?php

namespace App\Notifications\Billing;

use App\Models\Workspace;
use App\Notifications\Billing\Concerns\MentionsSupport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Buying, resizing or removing an add-on changes what is charged. Sent for
 * every entry point, including the extra seat bought inside the invite flow.
 */
class AddonChangedNotification extends Notification implements ShouldQueue
{
    use MentionsSupport, Queueable;

    public function __construct(
        private readonly Workspace $workspace,
        private readonly string $addon,
        private readonly int $quantity,
        // false for a plan granted by hand: nothing is charged, so nothing is prorated
        private readonly bool $prorated,
    ) {}

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $summary = $this->quantity <= 0
            ? "{$this->addon} has been removed from {$this->workspace->name}."
            : "{$this->workspace->name} now has {$this->addon} (quantity: {$this->quantity}).";

        $message = (new MailMessage)
            ->subject("Add-on updated for {$this->workspace->name}")
            ->greeting('Your add-ons have changed')
            ->line($summary);

        if ($this->prorated) {
            $message->line('Any difference in price is prorated by our payment provider for the rest of this billing period.');
        }

        return $this->withSupportLine($message->action('View billing', url('/billing')));
    }
}

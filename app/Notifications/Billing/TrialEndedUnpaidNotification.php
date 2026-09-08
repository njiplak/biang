<?php

namespace App\Notifications\Billing;

use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A trial with no card behind it has run out.
 *
 * Only reachable for a trial granted by hand (section 16: "Staff can grant one
 * by hand from the admin console"), because every customer-started trial now
 * collects a card up front and is converted by the provider instead.
 *
 * Section 6 sets the tone and the facts: going read-only deletes nothing, so
 * this is an invitation to pick a plan, not a notice of loss.
 */
class TrialEndedUnpaidNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Workspace $workspace,
        private readonly string $plan,
    ) {}

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your {$this->plan} trial for {$this->workspace->name} has ended")
            ->greeting('Your trial has ended')
            ->line("The {$this->plan} trial for {$this->workspace->name} has run its course. There was no card on file, so nothing has been charged.")
            ->line('The workspace is now read-only. Everything you created is still there and still yours, and stays readable for as long as you like.')
            ->action('Choose a plan', url('/billing'))
            ->line('Choosing a plan turns writing back on straight away. Nothing is deleted while you decide.');
    }
}

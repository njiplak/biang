<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Goes to the address the account CURRENTLY uses, not the new one.
 *
 * Without it, somebody holding a stolen session could walk the account to an
 * address of their own and the owner would learn about it only when they next
 * failed to log in. This is the one message that reaches the real owner while
 * they can still act on it.
 */
class EmailChangeRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $pendingEmail) {}

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A change to your email address was requested')
            ->greeting('Was this you?')
            ->line("Someone asked to change the email address on your account to {$this->pendingEmail}.")
            ->line('Nothing has changed yet. Your account still uses this address, and it will keep using it until the new one is confirmed.')
            ->action('Review your profile', route('profile.edit'))
            ->line('If this was not you, change your password now - someone else may be signed in to your account.');
    }
}

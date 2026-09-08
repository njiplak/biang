<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The framework's own ResetPassword notification builds its link from
 * route('password.reset') - the CUSTOMER form, backed by the `users` broker.
 * A staff token handed to it would be checked against the wrong table and
 * rejected, so the staff console needs its own link rather than its own copy
 * of the default.
 */
class AdminPasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = config('auth.passwords.admin_users.expire', 60);

        return (new MailMessage)
            ->subject('Reset your staff console password')
            ->greeting('Staff console')
            ->line('Someone asked to reset the password for this staff account.')
            ->action('Choose a new password', route('admin.password.reset', [
                'token' => $this->token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]))
            ->line("This link stops working in {$minutes} minutes.")
            // Staff accounts reach every customer's data, so an unexpected one
            // of these is worth somebody looking at rather than ignoring.
            ->line('If you did not ask for this, tell whoever runs the console.');
    }
}

<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Sent to the address somebody is trying to MOVE TO, which is why it is
 * delivered on demand rather than through $user->notify(): the notifiable is an
 * anonymous route, and the account it belongs to is carried explicitly.
 *
 * The framework's own verification mail cannot do this job. It hashes
 * getEmailForVerification() - the address already on the row - so it can only
 * ever confirm an address the account already has.
 */
class ConfirmEmailChangeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly User $user,
        private readonly string $pendingEmail,
    ) {}

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm your new email address')
            ->greeting('Confirm this address')
            ->line("Someone asked to move the account for {$this->user->name} to this address.")
            ->action('Confirm this address', $this->confirmationUrl())
            ->line('Until you confirm, the account keeps using its current address.')
            ->line('If you were not expecting this, you can ignore this email.');
    }

    private function confirmationUrl(): string
    {
        return URL::temporarySignedRoute(
            'settings.email.confirm',
            Carbon::now()->addMinutes((int) config('auth.verification.expire', 60)),
            [
                'id' => $this->user->getKey(),
                // Over the PENDING address, so a link stops working the moment
                // the person changes their mind and asks for a different one.
                'hash' => sha1($this->pendingEmail),
            ],
        );
    }
}

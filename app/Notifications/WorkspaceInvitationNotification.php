<?php

namespace App\Notifications;

use App\Models\WorkspaceInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Spec section 5 Path C: "Invite email → accept → you join an existing
 * workspace."
 *
 * Sent on demand, because the recipient usually has no account yet - that is
 * the point of an invitation. It carries the PLAINTEXT token, which exists only
 * in memory for the request that issued it; the database keeps a SHA-256 hash.
 */
class WorkspaceInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly WorkspaceInvitation $invitation,
        private readonly string $plainToken,
        private readonly string $invitedBy,
    ) {}

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $workspace = $this->invitation->workspace->name;

        return (new MailMessage)
            ->subject("{$this->invitedBy} invited you to {$workspace}")
            ->greeting('You have been invited')
            ->line("{$this->invitedBy} has invited you to join {$workspace} as a {$this->invitation->role->label()}.")
            ->action('Accept the invitation', route('invitation.show', $this->plainToken))
            ->line('This invitation expires on '.$this->invitation->expires_at->toDayDateTimeString().'.')
            ->line('If you were not expecting this, you can ignore this email.');
    }
}

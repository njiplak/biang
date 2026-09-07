<?php

namespace App\Notifications\Billing;

use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Section 9, second row: "Still failing → Reminder emails escalate in tone."
 *
 * What escalates is the DEADLINE, not the blame. The customer has not done
 * anything wrong, so each reminder says the same thing more urgently and more
 * specifically - the closer the read-only date, the more prominent it gets.
 *
 * Deliberately a separate class from PaymentFailedNotification even though the
 * subject matter is the same: they are sent under different dedupe keys and a
 * shared class would make "was the first one sent?" unanswerable in the log.
 */
class PaymentFailedReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
        // Counted from the start of today so the number matches what the
        // customer would count on a calendar, rather than rounding on the hour.
        // Carbon 3 returns a signed float here, and the match below compares
        // strictly - an uncast 0.0 would fall through to "0 days".
        $daysLeft = (int) max(0, now()->startOfDay()->diffInDays($this->graceEndsAt->copy()->startOfDay()));
        $urgent = $daysLeft <= 3;

        $message = (new MailMessage)
            ->subject($urgent
                ? "Action needed: {$this->workspace->name} goes read-only in {$this->countdown($daysLeft)}"
                : "Still unable to take payment for {$this->workspace->name}")
            ->greeting($urgent ? 'This is the last reminder before access changes' : 'We still cannot take payment');

        if ($urgent) {
            $message
                ->line("We have retried the payment for {$this->workspace->name} several times and it is still being declined.")
                ->line("In {$this->countdown($daysLeft)} the workspace becomes read-only and your team will not be able to make changes.")
                ->action('Update payment details now', url('/billing'));
        } else {
            $message
                ->line("The payment for {$this->workspace->name} is still outstanding, and our retries are still being declined.")
                ->line("Full access continues until {$this->graceEndsAt->toFormattedDayDateString()}. After that the workspace becomes read-only until the payment goes through.")
                ->action('Update payment details', url('/billing'));
        }

        // Section 6: "Cancelling does not delete anything." Worth repeating
        // here - the fear this email creates is about losing data, not access.
        return $message->line('Nothing is deleted at any point, and you can export your data at any time.');
    }

    private function countdown(int $daysLeft): string
    {
        return match ($daysLeft) {
            0 => 'less than a day',
            1 => '1 day',
            default => "{$daysLeft} days",
        };
    }
}

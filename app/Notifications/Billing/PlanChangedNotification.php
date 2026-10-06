<?php

namespace App\Notifications\Billing;

use App\Models\PlanPrice;
use App\Models\Workspace;
use App\Notifications\Billing\Concerns\MentionsSupport;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A plan switch is prorated and charged straight away, so the customer gets a
 * record of what changed before the charge shows on their statement.
 */
class PlanChangedNotification extends Notification implements ShouldQueue
{
    use MentionsSupport, Queueable;

    public function __construct(
        private readonly Workspace $workspace,
        private readonly string $fromPlan,
        private readonly string $toPlan,
        private readonly string $currency,
        private readonly int $amountMinor,
        private readonly string $interval,
        // false for a plan granted by hand: nothing is charged, so nothing is prorated
        private readonly bool $prorated,
        // set for a downgrade scheduled for the renewal; null means it applied now
        private readonly ?CarbonInterface $effectiveAt = null,
    ) {}

    public static function for(Workspace $workspace, string $fromPlan, PlanPrice $to, bool $prorated, ?CarbonInterface $effectiveAt = null): self
    {
        return new self(
            $workspace,
            $fromPlan,
            $to->plan->name,
            $to->currency,
            $to->amount_minor,
            $to->billing_interval->value,
            $prorated,
            $effectiveAt,
        );
    }

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $price = $this->currency.' '.number_format($this->amountMinor / 100, 2);

        if ($this->effectiveAt !== null) {
            $date = $this->effectiveAt->toFormattedDayDateString();

            return $this->withSupportLine((new MailMessage)
                ->subject("{$this->workspace->name} moves to {$this->toPlan} on {$date}")
                ->greeting('Your plan change is scheduled')
                ->line("{$this->workspace->name} stays on {$this->fromPlan} until {$date}, the end of the period you have already paid for. It then moves to {$this->toPlan} ({$price} per {$this->interval}, before tax).")
                ->line('Nothing is charged now. You can keep your current plan instead at any time before then.')
                ->action('View billing', url('/billing')));
        }

        $message = (new MailMessage)
            ->subject("{$this->workspace->name} is now on {$this->toPlan}")
            ->greeting('Your plan has changed')
            ->line("{$this->workspace->name} has moved from {$this->fromPlan} to {$this->toPlan} ({$price} per {$this->interval}, before tax).");

        if ($this->prorated) {
            // Section 8: Dodo does the proration, so we describe it rather than quote it.
            $message->line('The difference for the rest of this billing period is prorated by our payment provider and charged or credited straight away.');
        }

        return $this->withSupportLine($message->action('View billing', url('/billing')));
    }
}

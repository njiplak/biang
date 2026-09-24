<?php

namespace App\Notifications\Billing\Concerns;

use App\Support\SiteSettings;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Every billing email ends with a way to reach us. A customer confused by a
 * charge who finds no contact goes to their bank instead, and with Dodo as
 * merchant of record that is a chargeback rather than a support ticket.
 */
trait MentionsSupport
{
    protected function withSupportLine(MailMessage $message): MailMessage
    {
        $url = SiteSettings::supportUrl();

        // Staff have not set one yet: say nothing rather than point nowhere.
        if ($url === null) {
            return $message;
        }

        $contact = str_starts_with($url, 'mailto:') ? substr($url, strlen('mailto:')) : $url;

        return $message->line("Questions about your plan or a charge? Contact us at {$contact}.");
    }
}

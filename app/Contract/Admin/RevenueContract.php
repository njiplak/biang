<?php

namespace App\Contract\Admin;

/**
 * Section 10: "Understand the business. MRR and ARR. Signups this week. Trials
 * currently running. Trial-to-paid conversion rate. Churn. Which plans are
 * actually selling."
 *
 * Section 15 names the same numbers as the ones to measure from day one, and
 * calls trial-to-paid "the number this whole build exists to move".
 *
 * Every figure here is derived from OUR tables, never from the payment
 * provider. Section 8 splits it the other way round for money that has actually
 * moved - these are recurring-revenue figures, not accounting.
 */
interface RevenueContract
{
    public function summary(): array;
}

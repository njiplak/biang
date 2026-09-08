<?php

namespace App\Http\Requests\Billing;

use App\Enums\CancellationFeedback;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Section 15 wants voluntary churn split by reason, and this is where the
 * reason arrives.
 *
 * Everything here is `nullable`, and that is the point rather than an
 * oversight: `routes/web/billing.php` commits to never blocking the exit, and
 * a required field on the way out is a block. A cancellation with no answer at
 * all has to succeed exactly as it did before this existed.
 */
class CancelSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'feedback' => ['nullable', Rule::enum(CancellationFeedback::class)],
            // Long enough for a real sentence, short enough that nobody can
            // use the cancel endpoint as free storage.
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

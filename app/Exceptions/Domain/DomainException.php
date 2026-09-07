<?php

namespace App\Exceptions\Domain;

use RuntimeException;

/**
 * Base for business-rule failures raised by the Service layer.
 *
 * These are THROWN, never returned. A service that returns its error as a value
 * lets a caller inside an outer DB::transaction() carry on and commit a partial
 * write - and in this codebase that outer caller is usually billing.
 *
 * Rendered for the customer via bootstrap/app.php, so a controller does not
 * have to remember to catch anything.
 */
abstract class DomainException extends RuntimeException
{
    /** Safe to show a customer. Never leak ids or internals here. */
    abstract public function userMessage(): string;
}

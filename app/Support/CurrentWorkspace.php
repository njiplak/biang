<?php

namespace App\Support;

use App\Models\Workspace;

/**
 * The request's tenant context.
 *
 * Deliberately hand-rolled rather than stancl/tenancy: spec section 11 puts the
 * whole app on one address with a workspace switcher (section 2), so there is no
 * domain to identify a tenant from, and most of the product - signup, the
 * switcher itself, the admin console, Dodo webhooks - is central. A package
 * built around "one tenant owns everything" would spend its life in escape
 * hatches here.
 */
class CurrentWorkspace
{
    private ?Workspace $workspace = null;

    /** When true the scope is lifted even though a workspace is set. */
    private bool $lifted = false;

    public function set(?Workspace $workspace): static
    {
        $this->workspace = $workspace;

        return $this;
    }

    public function get(): ?Workspace
    {
        return $this->workspace;
    }

    public function id(): ?int
    {
        return $this->workspace?->id;
    }

    /** Whether queries should currently be constrained to a workspace. */
    public function has(): bool
    {
        return $this->workspace !== null && ! $this->lifted;
    }

    public function forget(): static
    {
        $this->workspace = null;

        return $this;
    }

    /**
     * Run a callback across every workspace, then restore the context.
     *
     * For the switcher, the admin console and webhook processing - the places
     * that legitimately need to see all tenants. The restore is in a finally so
     * an exception inside the callback cannot leave scoping off for the rest of
     * the request.
     */
    public function runWithout(callable $callback): mixed
    {
        $previous = $this->lifted;
        $this->lifted = true;

        try {
            return $callback();
        } finally {
            $this->lifted = $previous;
        }
    }
}

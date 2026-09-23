<?php

namespace App\Support;

use App\Models\Business;
use RuntimeException;

/**
 * The business the current request is acting within.
 *
 * Resolved once by {@see \App\Http\Middleware\SetCurrentBusiness} from validated
 * session state — never from user input. Every business-scoped query reads it
 * through the global scope, so getting this wrong is the one mistake that leaks
 * one tenant's financial data into another's screens.
 */
class CurrentBusiness
{
    private ?Business $business = null;

    /** Suspends scoping for a deliberate, authorized cross-business read. */
    private bool $unscoped = false;

    public function set(?Business $business): void
    {
        $this->business = $business;
    }

    public function get(): ?Business
    {
        return $this->unscoped ? null : $this->business;
    }

    public function id(): ?int
    {
        return $this->get()?->id;
    }

    public function isSet(): bool
    {
        return $this->get() !== null;
    }

    public function getOrFail(): Business
    {
        return $this->get() ?? throw new RuntimeException(
            'No business is selected for this request.'
        );
    }

    public function clear(): void
    {
        $this->business = null;
    }

    /**
     * Run a callback with business scoping suspended.
     *
     * The single legitimate use is a platform-admin view across every business.
     * It is a deliberate, greppable code path — never the accidental absence of
     * a filter — and callers are responsible for authorizing it first.
     *
     * @template TReturn
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withoutScope(callable $callback): mixed
    {
        $previous = $this->unscoped;
        $this->unscoped = true;

        try {
            return $callback();
        } finally {
            $this->unscoped = $previous;
        }
    }
}

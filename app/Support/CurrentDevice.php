<?php

namespace App\Support;

use App\Models\Device;

/**
 * The computer the current request came from.
 *
 * Set once per request by {@see \App\Http\Middleware\IdentifyDevice}, and
 * read by whatever records where something was entered. Empty for anything
 * that did not come from a signed-in browser: the scheduler, an import, a test.
 */
class CurrentDevice
{
    private ?Device $device = null;

    private ?string $sessionKey = null;

    public function set(?Device $device, ?string $sessionKey = null): void
    {
        $this->device = $device;
        $this->sessionKey = $sessionKey;
    }

    public function get(): ?Device
    {
        return $this->device;
    }

    public function id(): ?int
    {
        return $this->device?->id;
    }

    public function name(): ?string
    {
        return $this->device?->label();
    }

    /** Tells two sign-ins apart without being the session's own id. */
    public function sessionKey(): ?string
    {
        return $this->sessionKey;
    }
}

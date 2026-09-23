<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Support\CurrentBusiness;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes which business the request is acting within.
 *
 * The business is taken from the route, and membership is verified on every
 * request. It is never read from a form field or a query string: accepting a
 * business_id from user input is the classic way one tenant reads another's
 * ledger.
 */
class SetCurrentBusiness
{
    public function __construct(private readonly CurrentBusiness $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $business = $request->route('business');

        if (! $business instanceof Business) {
            abort(404);
        }

        $user = $request->user();
        abort_unless($user !== null, 403);

        // A platform admin may inspect any business; everyone else must be an
        // active member of this one.
        if (! $user->isPlatformAdmin() && ! $user->belongsToBusiness($business)) {
            abort(403, 'You do not have access to this business.');
        }

        $this->current->set($business);
        $request->session()->put('current_business_id', $business->id);

        // Keep Spatie's team context in step, so role checks resolve against
        // this business rather than leaking across tenants.
        setPermissionsTeamId($business->id);

        return $next($request);
    }
}

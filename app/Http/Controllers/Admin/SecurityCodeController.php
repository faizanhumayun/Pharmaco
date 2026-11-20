<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\CheckSecurityCode;
use App\Http\Requests\Admin\StoreSecurityCodeRequest;
use App\Models\SecurityCode;
use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The site's access gate, kept by the back office: whether it is on, and the
 * codes that open it. A code is switched off rather than deleted.
 */
class SecurityCodeController extends Controller
{
    public function index(): View
    {
        Gate::authorize('manage-platform');

        return view('admin.security-code.index', [
            'enabled' => CheckSecurityCode::gateEnabled(),
            'codes' => SecurityCode::query()->orderByDesc('status')->orderBy('code')->get(),
        ]);
    }

    /** The master switch. */
    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('manage-platform');

        $on = $request->boolean('security_code_enabled');

        // Switching the gate on with nothing to open it would shut every
        // business out of its own books.
        if ($on && ! SecurityCode::query()->where('status', true)->exists()) {
            return back()->withErrors(['status' => 'Switch on at least one code before requiring a security code.']);
        }

        SystemSetting::put('security_code_enabled', $on);

        return back()->with('status', $on
            ? 'The security code is now required to reach the site.'
            : 'The security code is no longer required.');
    }

    public function store(StoreSecurityCodeRequest $request): RedirectResponse
    {
        SecurityCode::create([...$request->validated(), 'status' => true]);

        return back()->with('status', 'Code added.');
    }

    /** Switch one code on or off. */
    public function toggle(SecurityCode $securityCode): RedirectResponse
    {
        Gate::authorize('manage-platform');

        $turningOff = $securityCode->status;

        if ($turningOff && CheckSecurityCode::gateEnabled()
            && ! SecurityCode::query()->where('status', true)->whereKeyNot($securityCode->id)->exists()) {
            return back()->withErrors(['status' => 'This is the last working code. Add another, or stop requiring a code first.']);
        }

        $securityCode->update(['status' => ! $turningOff]);

        return back()->with('status', "Code {$securityCode->code} switched ".($turningOff ? 'off.' : 'on.'));
    }
}

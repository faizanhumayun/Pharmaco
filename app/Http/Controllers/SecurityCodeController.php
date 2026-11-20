<?php

namespace App\Http\Controllers;

use App\Http\Middleware\CheckSecurityCode;
use App\Http\Requests\VerifySecurityCodeRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The page in front of the site when the security-code gate is on.
 */
class SecurityCodeController extends Controller
{
    public function show(): View
    {
        return view('security-code');
    }

    public function verify(VerifySecurityCodeRequest $request): RedirectResponse
    {
        if (CheckSecurityCode::verifyCode($request->validated('security_code'))) {
            $request->session()->put(config('security-code.session_key'), true);

            return redirect($request->session()->pull('url.intended', '/'));
        }

        return back()->withErrors([
            'security_code' => 'Invalid security code. Please try again.',
        ]);
    }
}

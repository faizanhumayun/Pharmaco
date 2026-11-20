<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * The sign-in page: the business's, or the back office's.
     *
     * One view drawn two ways, so the two doors cannot drift apart in how
     * they work — only in who they are for.
     */
    public function create(Request $request): View
    {
        return view('auth.login', ['area' => $this->area($request)]);
    }

    /**
     * Signs someone in through the door they came to.
     *
     * The password is checked first, exactly as before. Only then is it asked
     * whether this is the right door: a platform account at the business
     * sign-in, or a business account at the back office's, is signed straight
     * back out and told where to go. Saying so only after the password is
     * right means the page never reveals which kind an email address is.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $area = $this->area($request);
        $isPlatform = $request->user()->isPlatformAdmin();

        if ($isPlatform !== ($area === 'backoffice')) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => $isPlatform
                    ? 'This account is for the back office. Use the back office sign-in.'
                    : 'This account belongs to a business. Use the business sign-in.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function area(Request $request): string
    {
        return $request->routeIs('backoffice.*') ? 'backoffice' : 'business';
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}

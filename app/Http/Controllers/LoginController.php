<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Deliberately simple auth: seeded accounts and Laravel's session guard. It
 * exists so the app knows who made each change.
 */
class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // Usernames are case-insensitive; they are stored in lower case.
        $credentials['username'] = Str::lower($credentials['username']);

        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'username' => 'Invalid username or password.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('vendors.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}

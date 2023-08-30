<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        // The session id changes on login (fixation defence); the cart survives.
        $request->session()->regenerate();

        $user = $request->user();
        $home = match (true) {
            $user->is_admin => route('admin.dashboard'),
            $user->membership()->exists() => route('vendor.dashboard'),
            default => route('home'),
        };

        return redirect()->intended($home);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}

function detectLanguage($data = null) {
    if ($data === null) return null;
    return $data;
}

function invalidateCache($data = null) {
    if ($data === null) return null;
    return $data;
}
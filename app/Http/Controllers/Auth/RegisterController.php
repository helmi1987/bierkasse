<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AppSettings;
use App\Support\UserRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Self registration, only with the registration password (setting). The QR code in the
 * backend may carry the password as ?code=… to prefill it.
 */
class RegisterController extends Controller
{
    public function show(Request $request): View
    {
        return view('auth.register', [
            'open' => AppSettings::registrationOpen(),
            'code' => (string) $request->query('code', ''),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(AppSettings::registrationOpen(), 403);

        $request->merge(['username' => Str::lower(trim((string) $request->input('username')))]);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:255'],
            'username' => UserRules::username(),
            'password' => UserRules::password(),
        ] + UserRules::profile());

        $key = 'register:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withInput($request->except(['password', 'password_confirmation', 'code']))
                ->withErrors(['code' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)])]);
        }

        if (! hash_equals(AppSettings::registrationPassword(), (string) $data['code'])) {
            RateLimiter::hit($key, 900);

            return back()->withInput($request->except(['password', 'password_confirmation', 'code']))
                ->withErrors(['code' => __('register.code_wrong')]);
        }

        $user = User::query()->create([
            'username' => $data['username'],
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'email' => $data['email'] ?? null,
            'password' => $data['password'],
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
        ]);

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('auth.method', 'local');

        return redirect()->route('account.dashboard')->with('status', __('register.done'));
    }
}

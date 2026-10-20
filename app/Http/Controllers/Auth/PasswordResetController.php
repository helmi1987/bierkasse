<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MailSettings;
use App\Support\UserRules;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Password reset by e-mail (Laravel password broker). Only for accounts with an address.
 */
class PasswordResetController extends Controller
{
    public function request(): View
    {
        return view('auth.forgot');
    }

    public function email(Request $request): RedirectResponse
    {
        $data = $request->validate(['login' => ['required', 'string', 'max:255']]);
        $login = trim($data['login']);

        $user = User::query()->where('status', User::STATUS_ACTIVE)
            ->where(fn ($q) => $q->where('username', Str::lower($login))->orWhere('email', $login))
            ->whereNotNull('email')->where('email', '!=', '')
            ->first();

        if ($user) {
            try {
                MailSettings::apply();
                Password::broker()->sendResetLink(['email' => $user->email, 'id' => $user->id]);
            } catch (Throwable $e) {
                Log::warning('Password reset mail failed', ['user' => $user->id, 'error' => $e->getMessage()]);
            }
        }

        // Same answer whether the account exists or not.
        return back()->with('status', __('passwords.sent_generic'));
    }

    public function edit(Request $request, string $token): View
    {
        return view('auth.reset', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => UserRules::password(),
        ]);

        $status = Password::broker()->reset(
            ['email' => $data['email'], 'password' => $data['password'], 'password_confirmation' => $request->input('password_confirmation'), 'token' => $data['token']],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'must_change_password' => false, 'remember_token' => Str::random(60)])->save();
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))->withErrors(['email' => __($status)]);
        }

        return redirect()->route('login')->with('status', __($status));
    }
}

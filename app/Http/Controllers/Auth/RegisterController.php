<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use App\Services\KassierNotifier;
use App\Services\Ledger;
use App\Support\AppSettings;
use App\Support\Money;
use App\Support\UserRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
            'price' => AppSettings::beerPrice(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(AppSettings::registrationOpen(), 403);

        $key = 'register:'.$request->ip();
        $back = fn (string $field, string $message) => back()
            ->withInput($request->except(['password', 'password_confirmation', 'code']))
            ->withErrors([$field => $message]);

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return $back('code', __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]));
        }

        // The registration password is checked first: without it nothing else is validated,
        // so user names cannot be probed for existence.
        $code = $request->input('code');
        if (! is_string($code) || $code === '' || ! hash_equals(AppSettings::registrationPassword(), $code)) {
            RateLimiter::hit($key, 900);

            return $back('code', __('register.code_wrong'));
        }

        $request->merge(['username' => Str::lower(trim((string) $request->input('username')))]);
        try {
            $data = $request->validate([
                'username' => UserRules::username(),
                'password' => UserRules::password(),
            ] + UserRules::profile());
            $carryover = $this->carryover($request);
        } catch (ValidationException $e) {
            // Failed attempts (e.g. taken user names) count towards the limit as well.
            RateLimiter::hit($key, 900);

            throw $e;
        }

        [$user, $carryoverTx] = DB::transaction(function () use ($data, $carryover) {
            $user = User::query()->create([
                'username' => $data['username'],
                'first_name' => $data['first_name'] ?? null,
                'last_name' => $data['last_name'] ?? null,
                'email' => $data['email'] ?? null,
                'password' => $data['password'],
                'role' => User::ROLE_USER,
                'status' => User::STATUS_ACTIVE,
            ]);
            $tx = $carryover ? Ledger::reportCarryover($user, ...$carryover) : null;

            return [$user, $tx];
        });

        $this->notifyKassiers($user, $carryoverTx);

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('auth.method', 'local');

        return redirect()->route('account.dashboard')->with('status', __('register.done'));
    }

    /**
     * Optional carry-over from the old paper tally list: direction (debt/credit), unit (beers/chf)
     * and value. Empty value = none. Beers are converted at the current beer price.
     *
     * @return array{0:int, 1:?int, 2:?int}|null [signed amount in Rappen, quantity, unit price]
     *
     * @throws ValidationException
     */
    private function carryover(Request $request): ?array
    {
        $value = trim((string) $request->input('carryover_value', ''));
        if ($value === '') {
            return null;
        }

        $data = $request->validate([
            'carryover_direction' => ['required', Rule::in(['debt', 'credit'])],
            'carryover_unit' => ['required', Rule::in(['beers', 'chf'])],
            'carryover_value' => ['required', 'string', 'max:20'],
        ]);
        $sign = $data['carryover_direction'] === 'debt' ? -1 : 1;

        if ($data['carryover_unit'] === 'beers') {
            $price = AppSettings::beerPrice();
            $beers = ctype_digit($value) ? (int) $value : 0;
            if ($beers < 1 || $beers > Transaction::CARRYOVER_MAX_BEERS || $price <= 0) {
                throw ValidationException::withMessages(['carryover_value' => __('register.carryover_beers_invalid', ['max' => Transaction::CARRYOVER_MAX_BEERS])]);
            }

            return [$sign * $beers * $price, $beers, $price];
        }

        $amount = Money::parse($value);
        if ($amount === null || $amount <= 0 || $amount > Transaction::CARRYOVER_MAX_AMOUNT) {
            throw ValidationException::withMessages(['carryover_value' => __('register.carryover_chf_invalid', ['max' => chf(Transaction::CARRYOVER_MAX_AMOUNT)])]);
        }

        return [$sign * $amount, null, null];
    }

    /**
     * Tells kassiers and admins about the new account (only self registration, not accounts
     * created in the backend). Respects the notification switch; failures never block.
     */
    private function notifyKassiers(User $user, ?Transaction $carryover): void
    {
        $name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));
        $lines = [
            __('mail.register_line', ['name' => $user->displayName(), 'time' => local_time($user->created_at ?? now())]),
            '',
            __('mail.register_username', ['username' => $user->username]),
            __('mail.register_name', ['name' => $name !== '' ? $name : '–']),
        ];
        if (($user->email ?? '') !== '') {
            $lines[] = __('mail.register_email', ['email' => $user->email]);
        }
        if ($carryover) {
            $lines[] = '';
            $lines[] = __('mail.register_carryover', ['text' => $carryover->carryoverText()]);
        }

        KassierNotifier::send(
            __('mail.register_subject', ['user' => $user->username]),
            $lines,
            route('backend.users.show', $user),
            __('mail.register_footer'),
        );
    }
}

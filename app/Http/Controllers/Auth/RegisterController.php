<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\InvitationUsedException;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\RegistrationCode;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Audit;
use App\Services\KassierNotifier;
use App\Services\Ledger;
use App\Support\AppSettings;
use App\Support\Money;
use App\Support\RegistrationLink;
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
 * Self registration, depending on the registration mode (setting):
 * - closed: no registration (invitation links are invalid too),
 * - invite: only with a valid one-time invitation link (?einladung=<token>),
 * - password: with the registration password, with the flyer code of the backend QR/flyer
 *   (?flyer=…, replaces the password field) or with a valid invitation link.
 *   Old printed QR codes with ?code=<password> still prefill the password field.
 * In every mode: with a multi-use registration code of the admin (?zugang=…, QR/flyer); a locked
 * or unknown code leads to the start page with a hint.
 */
class RegisterController extends Controller
{
    /** Failed attempts (wrong password or token, form errors) per IP within the decay. */
    private const MAX_ATTEMPTS = 10;

    private const DECAY = 900;

    public function show(Request $request): View|RedirectResponse
    {
        $regCode = null;
        if ($this->regCode($request) !== '') {
            $regCode = $this->validRegCode($request);
            if ($regCode instanceof RedirectResponse) {
                return $regCode;
            }
        }

        $mode = AppSettings::registrationMode();
        $token = $regCode ? '' : $this->token($request);
        $invitation = null;
        $tokenState = null;

        if ($mode !== AppSettings::MODE_CLOSED && $token !== '') {
            $key = $this->limiterKey($request);
            if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
                $tokenState = 'throttled';
            } elseif ($invitation = Invitation::findValid($token)) {
                $tokenState = 'valid';
            } else {
                // Unknown tokens count like wrong registration passwords.
                RateLimiter::hit($key, self::DECAY);
                $tokenState = 'invalid';
            }
        }

        // Flyer code (only in mode "password"): counts like a wrong password when invalid.
        $flyer = $this->flyer($request);
        $flyerValid = false;
        if ($regCode === null && $invitation === null && $flyer !== '' && AppSettings::registrationOpen()) {
            $key = $this->limiterKey($request);
            if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
                $tokenState = 'throttled';
            } elseif (AppSettings::flyerCodeValid($flyer)) {
                $flyerValid = true;
            } else {
                RateLimiter::hit($key, self::DECAY);
                $tokenState = 'flyer_invalid';
            }
        }

        // Form: with a valid invitation or flyer code (no password field) or in password mode.
        $state = match (true) {
            $regCode !== null => 'reg_code',
            $mode === AppSettings::MODE_CLOSED => 'closed',
            $invitation !== null => 'invited',
            $flyerValid => 'flyer',
            AppSettings::registrationOpen() => 'password',
            $tokenState !== null => 'invalid',
            default => 'invite_only',
        };

        return view('auth.register', [
            'state' => $state,
            'tokenState' => $tokenState,
            'throttle' => $tokenState === 'throttled' ? __('auth.throttle', ['seconds' => RateLimiter::availableIn($this->limiterKey($request))]) : null,
            'inviter' => $invitation?->creator?->username,
            'token' => $invitation ? $token : '',
            'flyer' => $flyerValid ? $flyer : '',
            'regCode' => $regCode ? $this->regCode($request) : '',
            'code' => (string) $request->query('code', ''),
            'price' => AppSettings::beerPrice(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $regCode = null;
        if ($this->regCode($request) !== '') {
            $regCode = $this->validRegCode($request);
            if ($regCode instanceof RedirectResponse) {
                return $regCode;
            }
        }

        $mode = AppSettings::registrationMode();
        abort_if($mode === AppSettings::MODE_CLOSED && $regCode === null, 403);

        $key = $this->limiterKey($request);
        $back = fn (string $field, string $message) => back()
            ->withInput($request->except(['password', 'password_confirmation', 'code', RegistrationLink::FLYER_QUERY, RegistrationCode::QUERY]))
            ->withErrors([$field => $message]);

        // Only failed attempts count; an office shares one IP, so keep it moderate.
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return $back('code', __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]));
        }

        // Registration code, invitation token or registration password is checked first: without
        // it nothing else is validated, so user names cannot be probed for existence.
        $token = $regCode ? '' : $this->token($request);
        $invitation = $token !== '' ? Invitation::findValid($token) : null;
        if ($invitation === null && $regCode === null) {
            if ($token !== '') {
                RateLimiter::hit($key, self::DECAY);
                if (! AppSettings::registrationOpen()) {
                    return $back('code', __('register.invitation_invalid'));
                }
            }
            if (! AppSettings::registrationOpen()) {
                // Mode "invite": the registration password is not accepted.
                RateLimiter::hit($key, self::DECAY);

                return $back('code', __('register.invite_only'));
            }
            $flyer = $this->flyer($request);
            if ($flyer !== '') {
                // Flyer code instead of the registration password.
                if (! AppSettings::flyerCodeValid($flyer)) {
                    RateLimiter::hit($key, self::DECAY);

                    return $back('code', __('register.flyer_invalid'));
                }
            } else {
                $code = $request->input('code');
                if (! is_string($code) || $code === '' || ! hash_equals(AppSettings::registrationPassword(), $code)) {
                    RateLimiter::hit($key, self::DECAY);

                    return $back('code', __('register.code_wrong'));
                }
            }
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
            RateLimiter::hit($key, self::DECAY);

            throw $e;
        }

        try {
            [$user, $carryoverTx] = DB::transaction(function () use ($data, $carryover, $invitation, $regCode) {
                $user = User::query()->create([
                    'username' => $data['username'],
                    'first_name' => $data['first_name'] ?? null,
                    'last_name' => $data['last_name'] ?? null,
                    'email' => $data['email'] ?? null,
                    'password' => $data['password'],
                    'role' => User::ROLE_USER,
                    'status' => User::STATUS_ACTIVE,
                    'registration_code_id' => $regCode?->id,
                ]);
                // Consumed atomically inside the transaction: a parallel registration with the
                // same link rolls back its new user.
                if ($invitation && ! $invitation->consume($user)) {
                    throw new InvitationUsedException;
                }
                Audit::log('registration', $user, [], array_filter([
                    'username' => $user->username,
                    'email' => $user->email,
                    'invitation_id' => $invitation?->id,
                    'registration_code_id' => $regCode?->id,
                ], fn ($v) => $v !== null), $user);
                $tx = $carryover ? Ledger::reportCarryover($user, ...$carryover) : null;

                return [$user, $tx];
            });
        } catch (InvitationUsedException) {
            return $back('code', __('register.invitation_invalid'));
        }

        $this->notifyKassiers($user, $carryoverTx, $invitation, $regCode);

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('auth.method', 'local');

        return redirect()->route('account.dashboard')->with('status', __('register.done'));
    }

    private function token(Request $request): string
    {
        $token = $request->input(Invitation::QUERY, $request->query(Invitation::QUERY, ''));

        return is_string($token) ? trim($token) : '';
    }

    private function regCode(Request $request): string
    {
        $code = $request->input(RegistrationCode::QUERY, $request->query(RegistrationCode::QUERY, ''));

        return is_string($code) ? trim($code) : '';
    }

    /**
     * The registration code of the request, or a redirect to the start page with a hint when it
     * is locked or unknown (deleted). Unknown codes count like wrong registration passwords.
     */
    private function validRegCode(Request $request): RegistrationCode|RedirectResponse
    {
        $key = $this->limiterKey($request);
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return redirect()->route('login')->with('warning', __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]));
        }

        $code = RegistrationCode::findByCode($this->regCode($request));
        if ($code === null) {
            RateLimiter::hit($key, self::DECAY);

            return redirect()->route('login')->with('warning', __('register.reg_code_invalid'));
        }
        if ($code->isLocked()) {
            return redirect()->route('login')->with('warning', __('register.reg_code_locked'));
        }

        return $code;
    }

    private function flyer(Request $request): string
    {
        $flyer = $request->input(RegistrationLink::FLYER_QUERY, $request->query(RegistrationLink::FLYER_QUERY, ''));

        return is_string($flyer) ? trim($flyer) : '';
    }

    private function limiterKey(Request $request): string
    {
        return 'register:'.$request->ip();
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
    private function notifyKassiers(User $user, ?Transaction $carryover, ?Invitation $invitation, ?RegistrationCode $regCode): void
    {
        $name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));
        $rows = [
            __('mail.register_username') => $user->username,
            __('mail.register_name') => $name !== '' ? $name : '–',
            __('mail.register_email') => (string) ($user->email ?? ''),
            __('mail.register_invited_by') => $invitation ? ($invitation->creator?->username ?? '?') : '',
            __('mail.register_code') => $regCode ? $regCode->label : '',
            __('mail.register_time') => local_time($user->created_at ?? now()),
            __('mail.register_carryover') => $carryover ? $carryover->carryoverText() : '',
        ];

        KassierNotifier::send(
            __('mail.register_subject', ['user' => $user->username]),
            [__('mail.register_line', ['name' => $user->displayName()])],
            $rows,
            route('backend.users.show', $user),
            __('mail.register_button'),
            __('mail.register_title'),
            $carryover ? [__('mail.register_carryover_hint')] : [],
        );
    }
}

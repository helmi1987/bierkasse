<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ledger;
use App\Services\LedgerException;
use App\Support\Money;
use App\Support\UserRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * User management for kassiers and admins. Only admins change roles or touch admin accounts.
 */
class UserController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status') === 'left' ? User::STATUS_LEFT : User::STATUS_ACTIVE;
        $balances = Ledger::balances();

        return view('backend.users.index', [
            'users' => User::query()->where('status', $status)->orderBy('username')->get(),
            'balances' => $balances,
            'status' => $status,
        ]);
    }

    public function show(User $user): View
    {
        return view('backend.users.show', [
            'user' => $user,
            'balance' => $user->balance(),
            'open' => Ledger::openItems($user),
            'transactions' => Transaction::query()->with(['creator', 'confirmer'])->where('user_id', $user->id)->latest('id')->paginate(25),
        ]);
    }

    public function limit(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['overdraft_limit' => ['nullable', 'string', 'max:20']]);
        $value = trim((string) ($data['overdraft_limit'] ?? ''));
        $limit = null;
        if ($value !== '') {
            $limit = Money::parse($value);
            if ($limit === null || $limit < 0) {
                throw ValidationException::withMessages(['overdraft_limit' => __('ledger.amount_invalid')]);
            }
        }

        $user->forceFill(['overdraft_limit' => $limit])->save();

        return back()->with('status', __('users.limit_saved'));
    }

    public function password(Request $request, User $user): RedirectResponse
    {
        $this->guardAdminTarget($request, $user);
        $data = $request->validate(['password' => UserRules::password()]);

        $user->forceFill([
            'password' => $data['password'],
            'must_change_password' => $request->boolean('must_change'),
            'remember_token' => null,
        ])->save();

        return back()->with('status', __('users.password_set'));
    }

    public function resetTotp(Request $request, User $user): RedirectResponse
    {
        $this->guardAdminTarget($request, $user);
        $user->disableTotp();

        return back()->with('status', __('users.totp_reset_done'));
    }

    public function status(Request $request, User $user): RedirectResponse
    {
        $this->guardAdminTarget($request, $user);
        abort_if($user->id === $request->user()->id, 403);

        $active = $request->boolean('active');

        if (! $active) {
            // Money first: an account with a balance or open items leaves via the leave flow
            // or after a correction, never silently.
            $pending = Transaction::query()->where('user_id', $user->id)->pending()->exists();
            if ($user->balance() !== 0 || $pending) {
                return back()->with('error', __('users.deactivate_blocked'));
            }
            if ($user->isAdmin() && User::query()->where('role', User::ROLE_ADMIN)->where('status', User::STATUS_ACTIVE)->count() <= 1) {
                return back()->with('error', __('users.last_admin_active'));
            }
        }

        $user->forceFill([
            'status' => $active ? User::STATUS_ACTIVE : User::STATUS_LEFT,
            'left_at' => $active ? null : now(),
        ])->save();

        return back()->with('status', $active ? __('users.reactivated') : __('users.deactivated'));
    }

    public function deposit(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'string', 'max:20'],
            'method' => ['required', Rule::in(Transaction::METHODS)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $amount = Money::parse($data['amount']);
        if ($amount === null || $amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('ledger.amount_invalid')]);
        }

        try {
            Ledger::bookDeposit($user, $amount, $data['method'], $request->user(), $data['note'] ?? null);
        } catch (LedgerException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', __('ledger.deposit_booked'));
    }

    public function correction(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'string', 'max:20'],
            'note' => ['required', 'string', 'max:500'],
        ]);
        $amount = Money::parse($data['amount']);
        if ($amount === null || $amount === 0) {
            throw ValidationException::withMessages(['amount' => __('ledger.amount_invalid')]);
        }

        try {
            Ledger::correction($user, $amount, $data['note'], $request->user());
        } catch (LedgerException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', __('ledger.correction_booked'));
    }

    public function role(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['role' => ['required', Rule::in(User::ROLES)]]);

        // The last admin cannot lose the role.
        if ($user->isAdmin() && $data['role'] !== User::ROLE_ADMIN
            && User::query()->where('role', User::ROLE_ADMIN)->where('status', User::STATUS_ACTIVE)->count() <= 1) {
            return back()->with('error', __('users.last_admin'));
        }

        $user->forceFill(['role' => $data['role']])->save();

        return back()->with('status', __('users.role_saved'));
    }

    /** Kassiers must not take over admin accounts (password, TOTP, status). */
    private function guardAdminTarget(Request $request, User $user): void
    {
        abort_if($user->isAdmin() && ! $request->user()->isAdmin(), 403);
    }
}

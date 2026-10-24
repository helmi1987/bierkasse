<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Audit;
use App\Services\Ledger;
use App\Services\LedgerException;
use App\Services\Gifts;
use App\Services\Rounds;
use App\Support\Money;
use App\Support\UserRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        // Locked accounts are listed with the active ones (badge "Gesperrt"): they still have a balance.
        $statuses = $status === User::STATUS_LEFT ? [User::STATUS_LEFT] : [User::STATUS_ACTIVE, User::STATUS_LOCKED];

        return view('backend.users.index', [
            'users' => User::query()->whereIn('status', $statuses)->orderBy('username')->get(),
            'balances' => $balances,
            'status' => $status,
        ]);
    }

    public function show(User $user): View
    {
        return view('backend.users.show', [
            'user' => $user->loadMissing('lockedBy'),
            'adminOnlySettled' => \App\Services\Ledger::adminOnlySettled($user),
            'balance' => $user->balance(),
            'personalSso' => $user->personalSsoProvider()->withCount('identities')->first(),
            'open' => Ledger::openItems($user),
            'transactions' => Transaction::query()->with(['creator', 'confirmer', 'roundItems.user', 'giftParts.user', 'giftParent.user'])->where('user_id', $user->id)->latest('id')->paginate(25),
            'roundsGiven' => Rounds::beersGiven($user),
            'giftsGiven' => Gifts::beersGiven($user),
            'giftsReceived' => Gifts::beersReceived($user),
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

        Audit::save('user.limit', $user->forceFill(['overdraft_limit' => $limit]));

        return back()->with('status', __('users.limit_saved'));
    }

    public function password(Request $request, User $user): RedirectResponse
    {
        $this->guardAdminTarget($request, $user);
        $data = $request->validate(['password' => UserRules::password()]);

        Audit::save('user.password_reset', $user->forceFill([
            'password' => $data['password'],
            'must_change_password' => $request->boolean('must_change'),
            'remember_token' => null,
        ]), true);

        return back()->with('status', __('users.password_set'));
    }

    public function resetTotp(Request $request, User $user): RedirectResponse
    {
        $this->guardAdminTarget($request, $user);
        DB::transaction(function () use ($user) {
            $user->disableTotp();
            Audit::log('user.totp_reset', $user);
        });

        return back()->with('status', __('users.totp_reset_done'));
    }

    public function status(Request $request, User $user): RedirectResponse
    {
        $this->guardAdminTarget($request, $user);
        abort_if($user->id === $request->user()->id, 403);
        // A locked account is unlocked (or deactivated after unlocking) via its own actions.
        if ($user->isLocked()) {
            return back()->with('error', __('users.status_locked'));
        }

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

        Audit::save('user.status', $user->forceFill([
            'status' => $active ? User::STATUS_ACTIVE : User::STATUS_LEFT,
            'left_at' => $active ? null : now(),
        ]));

        return back()->with('status', $active ? __('users.reactivated') : __('users.deactivated'));
    }

    /**
     * Locks an account (kassier/admin, reason required). Unlike deactivating it works with debts or pending
     * items: balance and bookings stay, the user can no longer sign in. Kassiers cannot lock admins, nobody
     * locks himself, the last active admin cannot be locked.
     */
    public function lock(Request $request, User $user): RedirectResponse
    {
        $this->guardAdminTarget($request, $user);
        abort_if($user->id === $request->user()->id, 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => __('users.lock_reason_required')]);

        $error = DB::transaction(function () use ($user, $data, $request) {
            $fresh = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! $fresh->isActive()) {
                return __('users.lock_not_active');
            }
            if ($fresh->isAdmin() && User::query()->where('role', User::ROLE_ADMIN)->where('status', User::STATUS_ACTIVE)->count() <= 1) {
                return __('users.last_admin_lock');
            }
            Audit::save('user.lock', $fresh->forceFill([
                'status' => User::STATUS_LOCKED,
                'locked_at' => now(),
                'locked_reason' => trim($data['reason']),
                'locked_by' => $request->user()->id,
            ]));

            return null;
        });

        return $error !== null ? back()->with('error', $error) : back()->with('status', __('users.locked_done'));
    }

    /** Unlock: the account is active again (reason and lock data are cleared, the audit log keeps them). */
    public function unlock(Request $request, User $user): RedirectResponse
    {
        $this->guardAdminTarget($request, $user);
        if (! $user->isLocked()) {
            return back()->with('error', __('users.unlock_not_locked'));
        }

        Audit::save('user.unlock', $user->forceFill([
            'status' => User::STATUS_ACTIVE,
            'locked_at' => null,
            'locked_reason' => null,
            'locked_by' => null,
        ]));

        return back()->with('status', __('users.unlocked_done'));
    }

    /** Storno of a beer, round or given gift of this user (any time after the undo window, note required). */
    public function storno(Request $request, User $user, Transaction $transaction): RedirectResponse
    {
        abort_unless($transaction->user_id === $user->id, 404);
        $data = $request->validate(['note' => ['required', 'string', 'max:500']], ['note.required' => __('ledger.storno_note_required')]);

        try {
            Ledger::storno($transaction, $request->user(), $data['note']);
        } catch (LedgerException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', __('ledger.storno_done'));
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

        // Kassiers and admins need an e-mail address (notifications, password reset).
        if ($data['role'] !== User::ROLE_USER && (string) $user->email === '') {
            return back()->with('error', __('users.role_email_required'));
        }

        // "Nur Verwaltung" exists only for kassiers and admins.
        Audit::save('user.role', $user->forceFill(['role' => $data['role']] + ($data['role'] === User::ROLE_USER ? ['admin_only' => false] : [])));

        return back()->with('status', __('users.role_saved'));
    }

    /** "Nur Verwaltung" on/off (admins only; also for the own account). */
    public function adminOnly(Request $request, User $user): RedirectResponse
    {
        $on = $request->boolean('admin_only');

        try {
            Ledger::setAdminOnly($user, $on);
        } catch (LedgerException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', __($on ? 'account.admin_only_on' : 'account.admin_only_off'));
    }

    /** Kassiers must not take over admin accounts (password, TOTP, status). */
    private function guardAdminTarget(Request $request, User $user): void
    {
        abort_if($user->isAdmin() && ! $request->user()->isAdmin(), 403);
    }
}

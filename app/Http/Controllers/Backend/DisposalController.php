<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Disposal;
use App\Models\Setting;
use App\Models\User;
use App\Services\Disposals;
use App\Services\LedgerException;
use App\Support\AppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * "Entsorgungsbonus" (kassier and admin): guide, settings, status (lock and minimum, backend only),
 * report for a user (with override) and the list of disposals.
 */
class DisposalController extends Controller
{
    public function index(Request $request): View
    {
        $enabled = Disposals::isEnabled();
        $last = $enabled ? Disposals::lastActive()?->load('user') : null;

        return view('backend.disposals', [
            'enabled' => $enabled,
            'enabledAt' => Disposals::enabledAt(),
            'mode' => AppSettings::disposalMode(),
            'fixed' => AppSettings::disposalFixed(),
            'randomMax' => AppSettings::disposalRandomMax(),
            'lockDays' => AppSettings::disposalLockDays(),
            'minBeers' => AppSettings::disposalMinBeers(),
            'withdrawMinutes' => AppSettings::undoMinutes('disposal'),
            'rankingPublic' => AppSettings::disposalRankingPublic(),
            'last' => $last,
            'lockedUntil' => $last !== null ? Disposals::lockedUntil($last) : null,
            'beersSince' => $last !== null ? Disposals::beersSince($last->reported_at) : null,
            'blockReason' => Disposals::blockReason(),
            'users' => User::query()->where('status', User::STATUS_ACTIVE)->hasBeerAccount()
                ->whereKeyNot($request->user()->id)->get()
                ->sortBy(fn (User $u) => mb_strtolower($u->displayName()))->values(),
            'disposals' => Disposal::query()->with(['user', 'reporter', 'withdrawer', 'transaction'])
                ->latest('reported_at')->latest('id')->paginate(50),
            'viewer' => $request->user(),
        ]);
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'disposal_mode' => ['required', Rule::in(AppSettings::DISPOSAL_MODES)],
            'disposal_fixed' => ['required', 'integer', 'between:1,'.AppSettings::DISPOSAL_BEERS_MAX],
            'disposal_random_max' => ['required', 'integer', 'between:1,'.AppSettings::DISPOSAL_BEERS_MAX],
            'disposal_lock_days' => ['required', 'integer', 'between:0,'.AppSettings::DISPOSAL_LOCK_DAYS_MAX],
            'disposal_min_beers' => ['required', 'integer', 'between:0,'.AppSettings::DISPOSAL_MIN_BEERS_MAX],
            'disposal_withdraw_minutes' => ['required', 'integer', 'between:0,'.AppSettings::UNDO_MAX],
        ]);
        $enable = $request->boolean('disposal_enabled');

        $values = [
            AppSettings::DISPOSAL_ENABLED => $enable,
            AppSettings::DISPOSAL_MODE => $data['disposal_mode'],
            AppSettings::DISPOSAL_FIXED => (int) $data['disposal_fixed'],
            AppSettings::DISPOSAL_RANDOM_MAX => (int) $data['disposal_random_max'],
            AppSettings::DISPOSAL_LOCK_DAYS => (int) $data['disposal_lock_days'],
            AppSettings::DISPOSAL_MIN_BEERS => (int) $data['disposal_min_beers'],
            AppSettings::DISPOSAL_RANKING_PUBLIC => $request->boolean('disposal_ranking_public'),
            // Merged into the stored windows: the other kinds are edited in Admin > Einstellungen.
            AppSettings::UNDO_MINUTES => array_merge(
                (array) AppSettings::get(AppSettings::UNDO_MINUTES, []),
                ['disposal' => (int) $data['disposal_withdraw_minutes']],
            ),
        ];
        // Off -> on = restart: earlier disposals no longer count for the lock and the minimum.
        if ($enable && ! AppSettings::disposalEnabled()) {
            $values[AppSettings::DISPOSAL_ENABLED_AT] = now()->format(DATE_ATOM);
        }
        Setting::putMany($values);

        return back()->with('status', __('disposal.saved'));
    }

    public function report(Request $request): RedirectResponse
    {
        if (! Disposals::isEnabled()) {
            return back()->with('error', __('disposal.disabled'));
        }
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'override' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'required_if_accepted:override', 'string', 'max:500'],
        ], ['reason.required_if_accepted' => __('disposal.reason_required')]);
        $by = $request->user();
        if ((int) $data['user_id'] === $by->id) {
            return back()->withInput()->with('error', __('disposal.self_forbidden'));
        }
        $recipient = User::query()->find((int) $data['user_id']);
        if ($recipient === null) {
            throw ValidationException::withMessages(['user_id' => __('disposal.recipient_invalid')]);
        }

        $override = $request->boolean('override');
        if (! $override && Disposals::blockReason() !== null) {
            return back()->withInput()->with('error', __('disposal.blocked_backend'));
        }

        try {
            Disposals::report($recipient, $by, $override, $data['reason'] ?? null);
        } catch (LedgerException $e) {
            return back()->withInput()->with('error', $e->getMessage() === __('disposal.blocked') ? __('disposal.blocked_backend') : $e->getMessage());
        }

        return back()->with('status', __('disposal.reported_for', ['user' => $recipient->displayName()]));
    }

    public function withdraw(Request $request, Disposal $disposal): RedirectResponse
    {
        try {
            Disposals::withdraw($disposal, $request->user());
        } catch (LedgerException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', __('disposal.withdrawn'));
    }
}

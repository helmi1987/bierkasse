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

/**
 * "Entsorgungsbonus" (kassier and admin): guide, settings, status (lock and minimum, backend only),
 * report for a user (with override), the list of disposals and the ranking.
 * Since 1.39.0 part of "Lager", since 1.40.2 its tab "Entsorgung" (?tab=entsorgung, Livewire component
 * App\Livewire\Backend\Disposals); the forms still post here and every answer goes back to that tab.
 */
class DisposalController extends Controller
{
    /** Tab of the disposal area on the stock page. */
    public const TAB = 'entsorgung';

    /** URL of the tab "Entsorgung" on the stock page. */
    public static function url(): string
    {
        return route('backend.stock', ['tab' => self::TAB]);
    }

    /** The old page "/admin/entsorgung" (until 1.38): permanent redirect to the tab on the stock page. */
    public function index(): RedirectResponse
    {
        return redirect()->to(self::url(), 301);
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $this->validated($request, [
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

        return $this->toArea()->with('status', __('disposal.saved'));
    }

    public function report(Request $request): RedirectResponse
    {
        if (! Disposals::isEnabled()) {
            return $this->toArea()->with('error', __('disposal.disabled'));
        }
        $data = $this->validated($request, [
            'user_id' => ['required', 'integer'],
            'override' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'required_if_accepted:override', 'string', 'max:500'],
        ], ['reason.required_if_accepted' => __('disposal.reason_required')]);
        $by = $request->user();
        if ((int) $data['user_id'] === $by->id) {
            return $this->toArea()->withInput()->with('error', __('disposal.self_forbidden'));
        }
        $recipient = User::query()->find((int) $data['user_id']);
        if ($recipient === null) {
            throw ValidationException::withMessages(['user_id' => __('disposal.recipient_invalid')])->redirectTo(self::url());
        }

        $override = $request->boolean('override');
        if (! $override && Disposals::blockReason() !== null) {
            return $this->toArea()->withInput()->with('error', __('disposal.blocked_backend'));
        }

        try {
            Disposals::report($recipient, $by, $override, $data['reason'] ?? null);
        } catch (LedgerException $e) {
            return $this->toArea()->withInput()->with('error', $e->getMessage() === __('disposal.blocked') ? __('disposal.blocked_backend') : $e->getMessage());
        }

        return $this->toArea()->with('status', __('disposal.reported_for', ['user' => $recipient->displayName()]));
    }

    public function withdraw(Request $request, Disposal $disposal): RedirectResponse
    {
        try {
            Disposals::withdraw($disposal, $request->user());
        } catch (LedgerException $e) {
            return $this->toArea()->with('error', $e->getMessage());
        }

        return $this->toArea()->with('status', __('disposal.withdrawn'));
    }

    /** Redirect to the disposal area on the stock page (instead of back(): keeps the anchor). */
    private function toArea(): RedirectResponse
    {
        return redirect()->to(self::url());
    }

    /** Validates like $request->validate(), but failures go back to the disposal area too. */
    private function validated(Request $request, array $rules, array $messages = []): array
    {
        try {
            return $request->validate($rules, $messages);
        } catch (ValidationException $e) {
            throw $e->redirectTo(self::url());
        }
    }
}

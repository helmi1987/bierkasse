<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Transaction;
use App\Support\AppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Runde spendieren" (kassier and admin): on/off switch and the list of the rounds.
 * The switch is kept separate from the admin settings so it can move to the tenant settings.
 */
class RoundController extends Controller
{
    public function index(): View
    {
        $rounds = Transaction::query()->confirmed()->where('type', Transaction::TYPE_ROUND);

        return view('backend.rounds', [
            'allowed' => AppSettings::roundsAllowed(),
            'rounds' => (clone $rounds)->with(['user', 'roundItems.user'])->latest('id')->paginate(30),
            'total' => ['beers' => (int) (clone $rounds)->sum('quantity'), 'amount' => (int) -(clone $rounds)->sum('amount')],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Setting::put(AppSettings::ROUNDS_ENABLED, $request->boolean('rounds_enabled'));

        return back()->with('status', __('settings.saved'));
    }
}

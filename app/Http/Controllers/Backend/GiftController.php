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
 * "Bier verschenken" (kassier and admin): on/off switch and the list of the gifts (undo window: admin settings).
 */
class GiftController extends Controller
{
    public function index(): View
    {
        $gifts = Transaction::query()->confirmed()->where('type', Transaction::TYPE_GIFT)->whereNull('parent_id');

        return view('backend.gifts', [
            'allowed' => AppSettings::giftsAllowed(),
            'gifts' => (clone $gifts)->with(['user', 'giftParts.user'])->latest('id')->paginate(30),
            'total' => ['beers' => (int) (clone $gifts)->sum('quantity'), 'amount' => (int) -(clone $gifts)->sum('amount')],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Setting::put(AppSettings::GIFTS_ENABLED, $request->boolean('gifts_enabled'));

        return back()->with('status', __('settings.saved'));
    }
}

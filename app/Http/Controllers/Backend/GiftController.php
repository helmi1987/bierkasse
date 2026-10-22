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
 * "Bier verschenken" (kassier and admin): on/off switch, undo window and the list of the gifts.
 */
class GiftController extends Controller
{
    public function index(): View
    {
        $gifts = Transaction::query()->confirmed()->where('type', Transaction::TYPE_GIFT)->whereNull('parent_id');

        return view('backend.gifts', [
            'allowed' => AppSettings::giftsAllowed(),
            'undoMinutes' => AppSettings::giftUndoMinutes(),
            'gifts' => (clone $gifts)->with(['user', 'giftParts.user'])->latest('id')->paginate(30),
            'total' => ['beers' => (int) (clone $gifts)->sum('quantity'), 'amount' => (int) -(clone $gifts)->sum('amount')],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['gift_undo_minutes' => ['required', 'integer', 'between:0,60']]);
        Setting::putMany([
            AppSettings::GIFTS_ENABLED => $request->boolean('gifts_enabled'),
            AppSettings::GIFT_UNDO_MINUTES => (int) $data['gift_undo_minutes'],
        ]);

        return back()->with('status', __('settings.saved'));
    }
}

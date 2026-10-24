<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\AppSettings;
use App\Support\BackendNav;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Default order of the tabs under "Kassier" and "Admin" (admins). Every kassier/admin can
 * change the order for the own account in the account settings.
 */
class NavController extends Controller
{
    public function edit(): View
    {
        return view('backend.nav', [
            'groups' => array_keys(BackendNav::ITEMS),
            'orders' => BackendNav::defaults(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate(['order' => ['required', 'array']]);
        $order = [];
        foreach (array_keys(BackendNav::ITEMS) as $group) {
            $order[$group] = BackendNav::normalize($group, $request->input('order.'.$group));
        }
        Setting::put(AppSettings::NAV_ORDER, $order);

        return back()->with('status', __('backend.nav_saved'));
    }

    public function reset(): RedirectResponse
    {
        Setting::put(AppSettings::NAV_ORDER, []);

        return back()->with('status', __('backend.nav_reset_done'));
    }
}

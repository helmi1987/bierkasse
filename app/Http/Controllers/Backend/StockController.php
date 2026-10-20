<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\StockCorrection;
use App\Services\BeerStock;
use App\Support\AppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Beer stock (kassier and admin): current stock, minimum stock, inventory corrections.
 */
class StockController extends Controller
{
    public function index(): View
    {
        return view('backend.stock', [
            'stock' => BeerStock::current(),
            'minimum' => BeerStock::minimum(),
            'low' => BeerStock::isLow(),
            'corrections' => StockCorrection::query()->with('creator')->latest('id')->paginate(20),
        ]);
    }

    public function minimum(Request $request): RedirectResponse
    {
        $data = $request->validate(['minimum' => ['required', 'integer', 'min:0', 'max:100000']]);
        Setting::put(AppSettings::STOCK_MINIMUM, (int) $data['minimum']);
        BeerStock::rearm();

        return back()->with('status', __('settings.saved'));
    }

    public function correct(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'counted' => ['required', 'integer', 'min:-100000', 'max:100000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $correction = BeerStock::correct((int) $data['counted'], $data['note'] ?? null, $request->user());

        return back()->with('status', __('stock.corrected', ['delta' => sprintf('%+d', $correction->delta)]));
    }
}

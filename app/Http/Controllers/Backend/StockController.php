<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Setting;
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
    public function index(Request $request): View
    {
        // Tab "Entsorgung" (?tab=entsorgung, 1.40.2): Livewire component App\Livewire\Backend\Disposals.
        if ($request->query('tab') === DisposalController::TAB) {
            return view('backend.stock-disposals');
        }

        // Livewire component App\Livewire\Backend\Stock (1.36.0).
        return view('backend.stock');
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

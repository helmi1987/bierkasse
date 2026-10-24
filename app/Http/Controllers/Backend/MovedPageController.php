<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Admin pages moved in 1.20.0 (e.g. "Runden" → "Buchungen", tab "Runden"): permanent redirect to the
 * new route (route default "target") and tab (route default "tab"), keeping the query string and the
 * route parameters (e.g. the entry of an audit detail page).
 */
class MovedPageController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $route = $request->route();
        $target = (string) $route->defaults['target'];
        $parameters = array_diff_key($route->parameters(), array_flip(['target', 'tab']));
        $tab = $route->defaults['tab'] ?? null;

        return redirect()->route($target, $parameters + ($tab !== null ? ['tab' => $tab] : []) + $request->query(), 301);
    }
}

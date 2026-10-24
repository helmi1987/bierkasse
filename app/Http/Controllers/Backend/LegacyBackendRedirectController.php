<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The admin area moved from /backend to /admin in 1.20.0: old GET URLs (bookmarks, links in older
 * mails) are redirected permanently, query string included. POSTs to old URLs are not supported.
 * Printed QR codes never pointed to /backend (they encode /kasse, /gast and /registrieren).
 */
class LegacyBackendRedirectController extends Controller
{
    public function __invoke(Request $request, ?string $path = null): RedirectResponse
    {
        $target = '/admin'.($path !== null && $path !== '' ? '/'.ltrim($path, '/') : '');
        $query = $request->getQueryString();

        return redirect()->to(url($target).($query !== null && $query !== '' ? '?'.$query : ''), 301);
    }
}

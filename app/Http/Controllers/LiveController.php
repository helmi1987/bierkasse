<?php

namespace App\Http\Controllers;

use App\Support\LiveFingerprint;
use Illuminate\Http\JsonResponse;

/**
 * Polled by public/js/live.js every 10 seconds: only when the fingerprint differs from the one of the
 * shown page, the page itself is fetched again and swapped. Logged out = 401 (JSON).
 */
class LiveController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['fp' => LiveFingerprint::current()])
            ->header('Cache-Control', 'no-store, private');
    }
}

<?php

namespace App\Http\Middleware;

use App\Services\Disposals;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Catch-up for the "Entsorgungsbonus" when the cron (schedule:run, disposals:book) does not run: a page view
 * books due disposals, at most once per CATCHUP_SECONDS and only when one is due. Never breaks the request.
 */
class BookDueDisposals
{
    public const CATCHUP_KEY = 'disposals-catchup';

    public const CATCHUP_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('live', 'livewire.update', '*.livewire.update') && ! $request->is('*livewire/update')) {
            $this->catchUp();
        }

        return $next($request);
    }

    private function catchUp(): void
    {
        try {
            if (! config('app.installed') || ! Disposals::isEnabled() || ! Cache::add(self::CATCHUP_KEY, 1, self::CATCHUP_SECONDS)) {
                return;
            }
            if (Disposals::hasDue()) {
                Disposals::bookDue();
            }
        } catch (Throwable $e) {
            Log::warning('Disposal catch-up booking failed', ['error' => $e->getMessage()]);
        }
    }
}

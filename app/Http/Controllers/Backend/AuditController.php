<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Audit;
use App\Support\AuditPresenter;
use App\Support\AuditQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Audit log "Protokoll" (kassiers and admins, read-only): detail page, integrity check and CSV export; the list
 * is the Livewire component App\Livewire\Backend\AuditLog. Entries are never changed or deleted here.
 */
class AuditController extends Controller
{
    /** Page shell of the Livewire component App\Livewire\Backend\AuditLog (tabs "Protokoll" and "Fassungen"). */
    public function index(): View
    {
        return view('backend.audit.index');
    }

    public function show(AuditLog $entry): View
    {
        return view('backend.audit.show', ['entry' => $entry->load('actor'), 'presenter' => AuditPresenter::for([$entry])]);
    }

    /** Checks the whole hash chain and reports the first broken entry. */
    public function verify(): RedirectResponse
    {
        $result = Audit::verify();
        if ($result === null) {
            return back()->with('status', __('audit.verify_ok', ['count' => AuditLog::query()->count()]));
        }

        return back()->with('error', $result['id'] !== null
            ? __('audit.verify_broken', ['id' => $result['id'], 'reason' => __('audit.reason.'.$result['reason'])])
            : __('audit.verify_tail'));
    }

    public function export(Request $request): StreamedResponse
    {
        $query = AuditQuery::query(AuditQuery::filters($request->query()))->orderBy('id');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'created_at_utc', 'actor_id', 'actor_name', 'action', 'subject_type', 'subject_id', 'old', 'new', 'ip', 'user_agent', 'prev_hash', 'hash'], escape: '');
            foreach ($query->lazyById(500) as $e) {
                fputcsv($out, array_map(fn ($v) => self::csvSafe($v), [
                    $e->id, $e->created_at, $e->actor_ref, $e->actor_name, $e->action, $e->subject_type, $e->subject_id,
                    $e->old_values, $e->new_values, $e->ip, $e->user_agent, $e->prev_hash, $e->hash,
                ]), escape: '');
            }
            fclose($out);
        }, 'protokoll-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    /** Against formula injection in spreadsheet programs. */
    private static function csvSafe(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}

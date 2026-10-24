<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Audit;
use App\Support\AuditPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Audit log "Protokoll" (admins only, read-only). Entries are never changed or deleted here.
 */
class AuditController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $entries = $this->query($filters)->with('actor')->orderByDesc('id')->paginate(50)->withQueryString();

        return view('backend.audit.index', [
            'entries' => $entries,
            // Subjects and referenced users of this page, loaded in batches (no query per row).
            'presenter' => AuditPresenter::for($entries->items()),
            'filters' => $filters,
            'actors' => AuditLog::query()->whereNotNull('actor_ref')->select('actor_ref', DB::raw('MAX(actor_name) as actor_name'))
                ->groupBy('actor_ref')->orderBy('actor_name')->get(),
            'prefixes' => AuditLog::query()->distinct()->orderBy('action')->pluck('action')
                ->map(fn ($a) => explode('.', $a)[0])->unique()->values(),
        ]);
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
        $query = $this->query($this->filters($request))->orderBy('id');

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

    /**
     * @return array{actor: int|null, action: string, from: string, to: string, q: string}
     */
    private function filters(Request $request): array
    {
        $date = fn (string $key) => is_string($request->query($key)) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->query($key)) ? $request->query($key) : '';

        return [
            'actor' => is_numeric($request->query('actor')) ? (int) $request->query('actor') : null,
            'action' => is_string($request->query('action')) ? mb_substr(trim($request->query('action')), 0, 64) : '',
            'from' => $date('from'),
            'to' => $date('to'),
            'q' => is_string($request->query('q')) ? mb_substr(trim($request->query('q')), 0, 100) : '',
        ];
    }

    /** @return Builder<AuditLog> */
    private function query(array $f): Builder
    {
        $tz = (string) config('app.timezone');
        $utc = function (string $date, bool $end) use ($tz): ?string {
            try {
                $d = Carbon::createFromFormat('Y-m-d', $date, $tz);
            } catch (Throwable) {
                return null;
            }

            return ($end ? $d->endOfDay() : $d->startOfDay())->utc()->format('Y-m-d H:i:s');
        };

        return AuditLog::query()
            ->when($f['actor'] !== null, fn ($q) => $q->where('actor_ref', $f['actor']))
            ->when($f['action'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('action', $f['action'])->orWhere('action', 'like', addcslashes($f['action'], '%_\\').'.%')))
            ->when($f['from'] !== '' && $utc($f['from'], false), fn ($q) => $q->where('created_at', '>=', $utc($f['from'], false)))
            ->when($f['to'] !== '' && $utc($f['to'], true), fn ($q) => $q->where('created_at', '<=', $utc($f['to'], true)))
            ->when($f['q'] !== '', function ($q) use ($f) {
                $like = '%'.addcslashes($f['q'], '%_\\').'%';
                $q->where(fn ($w) => $w->where('actor_name', 'like', $like)->orWhere('action', 'like', $like)
                    ->orWhere('subject_type', 'like', $like)->orWhere('old_values', 'like', $like)
                    ->orWhere('new_values', 'like', $like)->orWhere('ip', 'like', $like));
            });
    }
}
